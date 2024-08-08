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

namespace Psc\Library\Net\Http\Server;

use Closure;
use P\IO;
use Psc\Core\Output;
use Psc\Core\Stream\SocketStream;
use Psc\Library\Net\Exception\ConnectionException;
use Psc\Library\Net\Http\Server\Exception\FormatException;
use Psc\Std\Stream\Exception\RuntimeException;
use Throwable;

use function call_user_func_array;
use function count;
use function explode;
use function P\async;
use function P\await;
use function strlen;
use function strtolower;

use function str_contains;

use const SO_KEEPALIVE;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_REUSEPORT;
use const SO_SNDBUF;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

/**
 * Http服务类
 */
class HttpServer
{
    /**
     * 请求处理器
     * @var Closure
     */
    public Closure       $onRequest;
    private SocketStream $server;

    /**
     * @param string     $address
     * @param mixed|null $context
     */
    public function __construct(string $address, mixed $context = null)
    {
        async(function () use (
            $address,
            $context
        ) {
            $addressExploded = explode('://', $address);
            if (count($addressExploded) !== 2) {
                throw new RuntimeException('Address format error');
            }

            $scheme             = $addressExploded[0];
            $tcpAddress         = $addressExploded[1];
            $tcpAddressExploded = explode(':', $tcpAddress);
            $host               = $tcpAddressExploded[0];
            $port               = $tcpAddressExploded[1] ?? match ($scheme) {
                'http' => 80,
                'https' => 443,
                default => throw new RuntimeException('Address format error')
            };

            /**
             * @var SocketStream $server
             */
            $this->server = match ($scheme) {
                'http' => await(IO::Socket()->streamSocketServer("tcp://{$host}:{$port}", $context)),
                'https' => await(IO::Socket()->streamSocketServerSSL("ssl://{$host}:{$port}", $context)),
                default => throw new RuntimeException('Address format error')
            };

            $this->server->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            $this->server->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
            $this->server->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
            $this->server->setBlocking(false);
        });
    }

    /**
     * @return void
     */
    public function listen(): void
    {
        $this->server->onReadable(function (SocketStream $stream) {
            try {
                $client = $stream->accept();
            } catch (Throwable) {
                return;
            }

            $client->setBlocking(false);

            /**
             * Debug: 低水位 & 缓冲区
             */
            //$lowWaterMarkRecv = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVLOWAT);
            //$lowWaterMarkSend = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDLOWAT);
            //$recvBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVBUF);
            //$sendBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDBUF);
            //var_dump($lowWaterMarkRecv, $lowWaterMarkSend, $recvBuffer, $sendBuffer);

            /**
             * 优化缓冲区: 256kb标准速率帧
             */
            $client->setOption(SOL_SOCKET, SO_RCVBUF, 256000);
            $client->setOption(SOL_SOCKET, SO_SNDBUF, 256000);
            $client->setOption(SOL_TCP, TCP_NODELAY, 1);

            /**
             * 设置发送低水位防止充盈内存
             * @deprecated 兼容未覆盖
             */
            //$client->setOption(SOL_SOCKET, SO_SNDLOWAT, 1024);

            /**
             * CPU亲密度
             * @deprecated 兼容未覆盖
             */
            //socket_set_option($clientSocket, SOL_SOCKET, SO_INCOMING_CPU, 1);
            $this->addClient($client);
        });
    }

    /**
     * @param SocketStream $stream
     * @return void
     */
    private function addClient(SocketStream $stream): void
    {
        $client =  new Connection($stream);
        $stream->onReadable(function (SocketStream $stream) use ($client) {
            $content = $stream->read(1024);
            if ($content === '') {
                $stream->close();
                return;
            }

            try {
                if(!$symfonyRequest = $client->tick($content)) {
                    return;
                }

                $keepAlive = false;
                if($connection = $symfonyRequest->headers->get('Connection')) {
                    if (str_contains(strtolower($connection), 'keep-alive')) {
                        $keepAlive = true;
                    }
                }

                $symfonyResponse = new Response($stream, function () use ($keepAlive, $stream) {
                    if (!$keepAlive) {
                        $stream->close();
                    }
                });

                $symfonyResponse->headers->set('Server', 'PServer');

                try {
                    if (isset($this->onRequest)) {
                        call_user_func_array($this->onRequest, [
                            $symfonyRequest,
                            $symfonyResponse
                        ]);
                    }
                    if ($keepAlive) {
                        $symfonyResponse->headers->set('Connection', 'keep-alive');
                    } else {
                        $stream->close();
                    }
                } catch (FormatException) {
                    /**
                     * 报文格式非法
                     */
                    try {
                        $stream->write("HTTP/1.1 400 Bad Request\r\n\r\n");
                    } catch (Throwable) {
                    }
                    $stream->close();
                } catch (ConnectionException) {
                    $stream->close();
                } catch (Throwable $e) {
                    /**
                     * 服务内部逻辑错误
                     */
                    $contentLength = strlen($message = $e->getMessage());
                    try {
                        $stream->write(
                            "HTTP/1.1 500 Internal Server Error\r\n" .
                            "Content-Type: text/plain\r\n" .
                            "Content-Length: {$contentLength}\r\n" .
                            "\r\n" .
                            $message
                        );
                    } catch (Throwable) {
                    }
                    $stream->close();
                }
            } catch (Throwable $exception) {
                Output::warning($exception->getMessage());
                $stream->close();
                return;
            }
        });
    }

    /**
     * @param Closure $onRequest
     * @return void
     */
    public function onRequest(Closure $onRequest): void
    {
        $this->onRequest = $onRequest;
    }
}
