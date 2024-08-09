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
use parallel\Events;
use parallel\Events\Event;
use Psc\Core\Coroutine\Promise;
use Throwable;

use function var_dump;

class Future
{
    /*** @var Closure */
    private Closure $onError;
    private mixed   $result;

    /*** @param \parallel\Future $future */
    public function __construct(public readonly \parallel\Future $future)
    {
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function value(): mixed
    {
        if (isset($this->result)) {
            return $this->result;
        }
        return $this->result = $this->future->value();
    }

    /**
     * @return bool
     */
    public function cancel(): bool
    {
        return $this->future->cancel();
    }

    /**
     * @return bool
     */
    public function done(): bool
    {
        return $this->future->done();
    }

    /**
     * @return void
     */
    public function cancelled(): void
    {
        $this->future->cancelled();
    }

    /**
     * @param Closure $onError
     * @return Future
     */
    public function onError(Closure $onError): Future
    {
        $this->onError = $onError;
        return $this;
    }

    /**
     * @param Event $event
     * @return void
     */
    public function onEvent(Events\Event $event): void
    {
        switch ($event->type) {
            case Events\Event\Type::Error:
                if (isset($this->onError)) {
                    ($this->onError)($event->value);
                }
                break;

            case Events\Event\Type::Cancel:
                if (isset($this->onCancelled)) {
                    ($this->onCancelled)($event->value);
                }
                break;
            case Events\Event\Type::Kill:
                if (isset($this->onKilled)) {
                    ($this->onKilled)($event->value);
                }
                break;
        }
    }

    /**
     * @var Closure
     */
    private Closure $onValue;

    /**
     * @param Closure $onValue
     * @return $this
     */
    public function onValue(Closure $onValue): Future
    {
        $this->onValue = $onValue;
        return $this;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function resolve(): void
    {
        if (isset($this->onValue)) {
            ($this->onValue)($this->result = $this->value());
        }
    }

    /**
     * @deprecated 用户的主动行为是否应该回调？
     * @var Closure
     */
    private Closure $onCancelled;

    /**
     * @deprecated 用户的主动行为是否应该回调？
     * @var Closure
     */
    private Closure $onKilled;

    /**
     * @deprecated 用户的主动行为是否应该回调？
     * @param Closure $onKilled
     * @return Future
     */
    public function onKilled(Closure $onKilled): Future
    {
        $this->onKilled = $onKilled;
        return $this;
    }

    /**
     * @deprecated 用户的主动行为是否应该回调？
     * @param Closure $onCancelled
     * @return Future
     */
    public function onCancelled(Closure $onCancelled): Future
    {
        $this->onCancelled = $onCancelled;
        return $this;
    }

    /**
     * @return Promise
     */
    public function getPromise(): Promise
    {
        return \P\promise(function (Closure $resolve, Closure $reject) {
            $this->onError($resolve);
            $this->onKilled($reject);
            $this->onCancelled($reject);

            if(isset($this->result)) {
                $resolve($this->result);
            } else {
                $this->onValue($resolve);
            }
        });
    }
}
