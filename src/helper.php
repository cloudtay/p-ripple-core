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

namespace P;

use Closure;
use Fiber;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Output;
use Revolt\EventLoop;
use Throwable;

/**
 * @param Promise $promise
 * @return mixed
 * @throws Throwable
 */
function await(Promise $promise): mixed
{
    return Coroutine::Async()->await($promise);
}

/**
 * async闭包中抛出的异常落地位置可能为调用上下文/挂起恢复处,因此对异常的管理要谨慎
 * @param Closure $closure
 * @return Promise
 */
function async(Closure $closure): Promise
{
    return Coroutine::Async()->async($closure);
}

/**
 * @param Closure $closure
 * @return Promise
 */
function promise(Closure $closure): Promise
{
    return new Promise($closure);
}

/**
 * @param int|float $second
 * @return void
 */
function sleep(int|float $second): void
{
    if (Fiber::getCurrent()) {
        try {
            await(async(function ($r) use ($second) {
                delay($second, function () use ($r) {
                    call_user_func($r);
                });
            }));
        } catch (Throwable $e) {
            Output::exception($e);
        }
    } else {
        $suspension = EventLoop::getSuspension();
        $callbackId = delay($second, fn() => $suspension->resume());
        try {
            $suspension->suspend();
        } finally {
            cancel($callbackId);
        }
    }
}

/**
 * @param int|float $second
 * @param Closure   $closure
 * @return string
 */
function delay(int|float $second, Closure $closure): string
{
    return EventLoop::delay($second, $closure);
}

/**
 * @param string $id
 * @return void
 */
function cancel(string $id): void
{
    EventLoop::cancel($id);
}

/**
 * @param int|float             $second
 * @param Closure(Closure):void $closure
 * @return string
 */
function repeat(int|float $second, Closure $closure): string
{
    return EventLoop::repeat($second, function ($cancelId) use ($closure) {
        call_user_func($closure, fn() => EventLoop::cancel($cancelId));
    });
}

/**
 * @param int     $signal
 * @param Closure $closure
 * @return string
 * @throws EventLoop\UnsupportedFeatureException
 */
function onSignal(int $signal, Closure $closure): string
{
    return EventLoop::onSignal($signal, function (string $cancelId) use ($closure) {
        try {
            call_user_func($closure);
        } catch (Throwable $e) {
            Output::exception($e);
        }
    });
}

/**
 * @param Closure $closure
 * @return int
 */
function fork(Closure $closure): int
{
    $processId = pcntl_fork();
    if ($processId === 0) {
        EventLoop::setDriver((new EventLoop\DriverFactory())->create());
        $closure();
        run();
    }
    return $processId;
}

/**
 * @return void
 */
function tick(): void
{
    EventLoop::run();
}

/**
 * @param int $microseconds
 * @return void
 */
function run(int $microseconds = 100000): void
{
    while (true) {
        tick();
        usleep($microseconds);
    }
}
