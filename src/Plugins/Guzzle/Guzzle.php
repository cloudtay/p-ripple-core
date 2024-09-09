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

namespace Psc\Plugins\Guzzle;

use GuzzleHttp\Client;
use Psc\Core\Http\Client\HttpClient;
use Psc\Core\LibraryAbstract;
use Psc\Plugins\Guzzle\Handler\PHandler;

use function array_merge;

use const PHP_SAPI;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class Guzzle extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /*** @var PHandler */
    private PHandler $pHandler;

    /*** @var Client */
    private Client $client;

    /**
     * @var HttpClient
     */
    private HttpClient $httpClient;

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->httpClient = new HttpClient(array_merge(['pool' => PHP_SAPI === 'cli'], $config));
        $this->pHandler   = new PHandler($this->httpClient);
        $this->client     = new Client(['handler' => $this->pHandler]);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/24 15:02
     * @return Client
     */
    public function client(): Client
    {
        return $this->client;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 11:46
     *
     * @param array $config
     *
     * @return Client
     */
    public function newClient(array $config = []): Client
    {
        return new Client(array_merge(['handler' => $this->pHandler], $config));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:28
     * @return PHandler
     */
    public function getHandler(): PHandler
    {
        return $this->pHandler;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:30
     * @return HttpClient
     */
    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }
}
