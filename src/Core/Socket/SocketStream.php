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

namespace Psc\Core\Socket;

use Co\IO;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Stream;
use RuntimeException;
use Socket;
use Throwable;
use Exception;

use function explode;
use function file_exists;
use function intval;
use function socket_get_option;
use function socket_import_stream;
use function socket_last_error;
use function socket_recv;
use function socket_set_option;
use function socket_strerror;
use function stream_socket_accept;
use function stream_socket_get_name;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const SO_SNDLOWAT;
use const SOL_SOCKET;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class SocketStream extends Stream
{
    /**
     * @var Socket
     */
    public Socket $socket;

    /**
     * @var bool
     */
    private bool $blocking = false;

    /**
     * @var Stream|null
     */
    private Stream|null $storageCacheWrite = null;

    /**
     * @var Stream|null
     */
    private Stream|null $storageCacheRead = null;

    /**
     * @var string|null
     */
    private string|null $address;

    /**
     * @var string|null
     */
    private string|null $host;

    /**
     * @var int|null
     */
    private int|null $port;

    /**
     * @param mixed       $resource
     * @param string|null $peerName
     */
    public function __construct(mixed $resource, string|null $peerName = null)
    {
        parent::__construct($resource);

        if (!$socket = socket_import_stream($this->stream)) {
            throw new RuntimeException('Failed to import stream');
        }

        $this->socket = $socket;

        if (!$peerName) {
            $peerName = stream_socket_get_name($this->stream, true);
        }

        if ($peerName === false) {
            $peerName = null;
        }

        $this->address = $peerName;

        if ($this->address) {
            $exploded   = explode(':', $this->address);
            $this->host = $exploded[0];
            $this->port = intval($exploded[1] ?? 0);
        }
    }

    /**
     * @return $this
     */
    public function accept(int|float $timeout = 0): SocketStream
    {
        $socket = @stream_socket_accept($this->stream, $timeout, $peerName);
        if ($socket === false) {
            throw new RuntimeException('Failed to accept connection: ' . socket_strerror(socket_last_error($this->socket)));
        }
        return new static($socket, $peerName);
    }

    /**
     * @param int   $level
     * @param int   $option
     * @param mixed $value
     *
     * @return void
     */
    public function setOption(int $level, int $option, mixed $value): void
    {
        if (!socket_set_option($this->socket, $level, $option, $value)) {
            throw new RuntimeException('Failed to set socket option: ' . socket_strerror(socket_last_error($this->socket)));
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/2 20:41
     *
     * @param int      $length
     * @param mixed    $target
     * @param int|null $flags
     *
     * @return int
     * @throws ConnectionException
     */
    public function recv(int $length, mixed &$target, int|null $flags = 0): int
    {
        $realLength = socket_recv($this->socket, $target, $length, $flags);
        if ($realLength === false) {
            throw new ConnectionException('Unable to read from stream');
        }
        return $realLength;
    }


    /**
     * @param string $string
     *
     * @return int
     * @throws ConnectionException
     */
    public function write(string $string): int
    {
        try {
            return $this->writeInternal($string)->await();
        } catch (Throwable $e) {
            throw new ConnectionException($e->getMessage(), 0, $e);
        }
    }

    /*** @var \Psc\Core\Coroutine\Promise */
    private Promise $writePromise;

    /**
     * @param string $string
     *
     * @return Promise<int>
     * @throws \Psc\Core\Stream\Exception\ConnectionException
     */
    private function writeInternal(string $string): Promise
    {
        if ($this->storageCacheWrite) {
            $this->storageCacheWrite->write($string);
            return $this->writePromise;
        }

        return $this->writePromise = \Co\promise(function ($resolve, $reject) use ($string) {
            try {
                if ($this->blocking) {
                    if ($this->storageCacheWrite === null) {
                        $tempFilePath            = sys_get_temp_dir() . '/' . uniqid('buf_');
                        $this->storageCacheWrite = IO::File()->open($tempFilePath, 'w+');
                        $this->storageCacheRead  = IO::File()->open($tempFilePath, 'r+');

                        $this->storageCacheWrite->setBlocking(true);

                        $closeEventId = $this->onClose(function () use ($tempFilePath) {
                            $this->storageCacheWrite->close();
                            $this->storageCacheRead->close();
                            if (file_exists($tempFilePath)) {
                                unlink($tempFilePath);
                            }
                        });

                        $this->onWritable(function ($_, $cancel) use ($tempFilePath, $closeEventId, $reject) {
                            if ($this->storageCacheRead->eof()) {
                                $this->blocking = false;
                                $this->storageCacheWrite->close();
                                $this->storageCacheRead->close();
                                if (file_exists($tempFilePath)) {
                                    unlink($tempFilePath);
                                }
                                $this->storageCacheWrite = null;
                                $this->storageCacheRead  = null;
                                $cancel();
                                $this->cancelOnClose($closeEventId);
                                return;
                            }

                            $buffer = $this->storageCacheRead->read($this->getOption(SOL_SOCKET, SO_SNDLOWAT));
                            try {
                                parent::write($buffer);
                            } catch (ConnectionException $e) {
                                $cancel();
                                $reject($e);
                            }
                        });
                    }

                    $this->storageCacheWrite->write($string);
                } else {
                    $length          = parent::write($string);
                    $remainingString = substr($string, $length);

                    if ($remainingString !== '') {
                        $this->blocking = true;
                        $this->writeInternal($remainingString)->await();
                    }

                    $resolve($length);
                }
            } catch (Exception $e) {
                $reject($e);
            }
        });
    }


    /**
     * @param int $level
     * @param int $option
     *
     * @return array|int
     */
    public function getOption(int $level, int $option): array|int
    {
        $option = socket_get_option($this->socket, $level, $option);
        if ($option === false) {
            throw new RuntimeException('Failed to get socket option: ' . socket_strerror(socket_last_error($this->socket)));
        }
        return $option;
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }
}
