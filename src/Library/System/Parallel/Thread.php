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

namespace Psc\Library\System\Parallel;

use Closure;
use Exception;
use P\System;
use parallel\Runtime;

class Thread
{
    /*** @var Runtime */
    private readonly Runtime $runtime;

    /*** @var Closure */
    private Closure $guide;

    /*** @var Context */
    private Context $context;

    /**
     * @param Closure  $handler
     * @param string   $name
     */
    public function __construct(
        private readonly Closure $handler,
        private readonly string  $name,
    ) {
        $this->runtime = new Runtime(Parallel::$autoload);
        $this->guide = static function (Closure $handler, Context $context, \parallel\Channel $channel) {
            $result = $handler($context);

            //@reason: 非kill/cancel/close/error无法触发events的loop,因此需要通过channel通知主线程
            $channel->send($context->name);

            /**
             * 弃用:非kill/cancel/close/error无法触发events的loop,因此需要通过channel通知主线程, 它可能发生在EventLoop初始化前
             */
            return $result;

        };
        $this->context = new Context();
    }

    /**
     * @param array<mixed> ...$argv
     * @return Future
     * @throws Exception
     */
    public function run(mixed ...$argv): Future
    {
        $this->context->argv    = $argv;
        $this->context->name = $this->name;
        $future              = new Future($this->runtime->run($this->guide, [
            $this->handler,
            $this->context,
            \parallel\Channel::open('future'),
        ]));
        System::Parallel()->listenFuture($future, $this->name);
        return $future;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->runtime->close();
    }

    /**
     * @return void
     */
    public function kill(): void
    {
        $this->runtime->kill();
    }
}
