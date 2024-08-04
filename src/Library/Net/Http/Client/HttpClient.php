<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Library\Net\Http\Client;

use Closure;
use GuzzleHttp\Psr7\MultipartStream;
use InvalidArgumentException;
use P\IO;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Stream\SocketStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function fclose;
use function fopen;
use function implode;
use function in_array;
use function P\async;
use function P\await;
use function P\cancel;
use function P\delay;
use function P\repeat;
use function str_contains;
use function strtolower;

class HttpClient
{
    /*** @var ConnectionPool */
    private ConnectionPool $connectionPool;

    /*** @var bool */
    private bool $pool;

    /*** @param array $config */
    public function __construct(private readonly array $config = [])
    {
        $pool = $this->config['pool'] ?? 'off';
        $this->pool = in_array($pool, [true, 1, 'on'], true);

        if ($this->pool) {
            $this->connectionPool = new ConnectionPool();
        }
    }

    /**
     * @param RequestInterface $request
     * @param array            $option
     * @return Promise<ResponseInterface>
     */
    public function request(RequestInterface $request, array $option = []): Promise
    {
        return async(function () use ($request, $option) {
            return \P\promise(function (Closure $r, Closure $d, Promise $promise) use ($request, $option) {
                $uri = $request->getUri();

                $method = $request->getMethod();
                $scheme = $uri->getScheme();
                $host   = $uri->getHost();
                $port   = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
                $path   = $uri->getPath() ?: '/';

                /**
                 * @var Connection $connection
                 */
                $connection = await($this->pullConnection(
                    $host,
                    $port,
                    $scheme === 'https'
                ));

                $header = "{$method} {$path} HTTP/1.1\r\n";
                foreach ($request->getHeaders() as $name => $values) {
                    $header .= "{$name}: " . implode(', ', $values) . "\r\n";
                }

                $bodyStream = $request->getBody();
                if ($bodyStream->getMetadata('uri') === 'php://temp') {
                    $body = $bodyStream->getContents();
                    $connection->stream->write("{$header}\r\n{$body}");
                } elseif ($bodyStream instanceof MultipartStream) {
                    if (!$request->getHeader('Content-Type')) {
                        $header .= "Content-Type: multipart/form-data; boundary={$bodyStream->getBoundary()}\r\n";
                    }
                    if (!$request->getHeader('Content-Length')) {
                        $header .= "Content-Length: {$bodyStream->getSize()}\r\n";
                    }
                    $connection->stream->write("{$header}\r\n");
                    repeat(function (Closure $cancel) use ($connection, $bodyStream, $r, $d) {
                        try {
                            $content = $bodyStream->read(8192);
                            if ($content) {
                                $connection->stream->write($content);
                            } else {
                                $cancel();
                                $bodyStream->close();
                            }
                        } catch (Throwable) {
                            $cancel();
                            $bodyStream->close();
                            $d(new InvalidArgumentException('Invalid body stream'));
                        }
                    }, 0.1);
                } else {
                    throw new InvalidArgumentException('Invalid body stream');
                }

                if ($timeout = $option['timeout'] ?? null) {
                    $delay = delay(function () use ($connection, $d) {
                        $connection->stream->close();
                        $d(new InvalidArgumentException('Request timeout'));
                    }, $timeout);
                    $promise->finally(function () use ($delay) {
                        cancel($delay);
                    });
                }

                if($sink = $option['sink'] ?? null) {
                    $connection->setOutput($sinkFile = fopen($sink, 'wb'));
                    $promise->finally(function () use ($sinkFile) {
                        fclose($sinkFile);
                    });
                }

                $connection->stream->onReadable(function (SocketStream $socketStream) use ($connection, $scheme, $r, $d) {
                    try {
                        $content = $socketStream->read(1024);
                        if ($response = $connection->tick($content)) {
                            $k = implode(', ', $response->getHeader('Connection'));
                            if (str_contains(strtolower($k), 'keep-alive') && $this->pool) {
                                $this->pushConnection($connection, $scheme === 'https');
                            } else {
                                $socketStream->close();
                            }
                            $r($response);
                        }
                    } catch (Throwable $exception) {
                        $socketStream->close();
                        $d($exception);
                        return;
                    }
                });
            });
        });
    }

    /**
     * @param string $host
     * @param int    $port
     * @param bool   $ssl
     * @return Promise<Connection>
     * @throws Throwable
     */
    private function pullConnection(string $host, int $port, bool $ssl): Promise
    {
        return async(function () use ($host, $port, $ssl) {
            if ($this->pool) {
                $connection =  await($this->connectionPool->pullConnection($host, $port, $ssl));
            } else {
                $connection =  $ssl
                    ? new Connection(await(IO::Socket()->streamSocketClientSSL("ssl://{$host}:{$port}")))
                    : new Connection(await(IO::Socket()->streamSocketClient("tcp://{$host}:{$port}")));
            }

            $connection->stream->setBlocking(false);
            return $connection;
        });
    }

    /**
     * @param Connection $connection
     * @param bool       $ssl
     * @return void
     */
    private function pushConnection(Connection $connection, bool $ssl): void
    {
        if ($this->pool) {
            $this->connectionPool->pushConnection($connection, $ssl);
        }
    }
}
