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
use Composer\Autoload\ClassLoader;
use parallel\Events;
use parallel\Runtime;
use parallel\Sync;
use Psc\Core\LibraryAbstract;
use ReflectionClass;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function count;
use function dirname;
use function file_exists;
use function intval;
use function is_int;
use function P\cancel;
use function P\defer;
use function P\onSignal;
use function P\registerForkHandler;
use function P\repeat;
use function posix_getpid;
use function posix_kill;
use function preg_match;
use function shell_exec;
use function strval;
use function usleep;

use const SIGUSR2;

/**
 * @Description 未通过测试
 * 这个扩展有很多玄学坑因此放弃了对它的封装,以下为踩坑笔记,下次重拾前掂量掂量
 *
 * 2024-08-07
 * 0x00 允许保留USR2信号，以便在主线程中执行并行代码
 * 0x01 用独立线程监听计数指令向主进程发送信号,原子性保留主进程events::poll的堵塞机制
 */
class Parallel extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /*** @var int */
    public static int $cpuCount;

    /*** @var string */
    public static string $autoload;

    /*** @var Events */
    private Events $events;

    /*** @var Thread[] */
    private array $threads = [];

    /*** @var Future[] */
    private array $futures = [];

    /**
     * 递归索引
     * @var int
     */
    private int $index = 0;

    /**
     * 事件分发线程
     * @var Runtime
     */
    private Runtime $counterRuntime;

    /**
     * 事件分发线程Future
     * @var \parallel\Future
     */
    private \parallel\Future $counterFuture;

    /**
     * 事件计数通道
     * @var Channel
     */
    private Channel $counterChannel;

    /**
     * 事件计数标量
     * @var Sync
     */
    private Sync $eventScalar;

    /**
     * 初始化标量
     * @var Sync
     */
    private Sync $initScalar;

    /**
     * Parallel constructor.
     */
    public function __construct()
    {
        $this->initialize();
    }

    /**
     * @return void
     */
    private function initialize(): void
    {
        // 初始化自动加载地址
        $this->initializeAutoload();

        // 获取CPU核心数
        Parallel::$cpuCount = intval(
            // if
            file_exists('/usr/bin/nproc')
                ? shell_exec('/usr/bin/nproc')
                : ( //else
                    file_exists('/proc/cpuinfo') && preg_match('/^processor\s+:/', shell_exec('cat /proc/cpuinfo'), $matches)
                        ? count($matches)
                        : ( //else
                            shell_exec('sysctl -n hw.ncpu')
                                ? shell_exec('sysctl -n hw.ncpu')
                                : 1 //else
                        )
                )
        );

        // 初始化事件处理器
        $this->events = new Events();
        $this->events->setBlocking(true);

        /**
         * 初始化future通道,坑如下:
         * 已解决:根据文档所示,recv在任何模式下都会被堵塞
         */
        $this->counterChannel = $this->makeChannel('counter');

        // 初始化标量同步器
        $this->eventScalar = new Sync(0);

        // 初始化标量
        $this->initScalar = new Sync(false);

        defer(function () {
            $this->initScalar->set(true);
            $this->initScalar->notify();
        });

        // @WeakReference::create无法引用
        $this->counterRuntime = new Runtime();
        $this->counterFuture = $this->counterRuntime->run(static function (\parallel\Channel $channel, Sync $eventScalar, Sync $initScalar) {
            do {
                $initScalar->wait();
            } while(!$initScalar->get());
            //            while (!$initScalar->get()) {
            //                usleep(100);
            //            }
            $processId = posix_getpid();
            $count = 0;
            while($number = $channel->recv()) {
                $eventScalar->set($count += $number);
                if($number > 0) {
                    posix_kill($processId, SIGUSR2);
                }
                if($count < 0) {
                    break;
                }
            }
        }, [$this->counterChannel->channel, $this->eventScalar,$this->initScalar]);

        // 注册多进程回收器
        $this->registerForkHandler();

        // 注册信号处理器
        $this->registerSignalHandler();
    }

    /**
     * @var string
     */
    private string $observerId;

    /**
     * @return void
     */
    private function initializeAutoload(): void
    {
        $reflector = new ReflectionClass(ClassLoader::class);
        $vendorDir = dirname($reflector->getFileName(), 2);
        Parallel::$autoload = "{$vendorDir}/autoload.php";
    }

    /**
     * @return void
     */
    private function poll(): void
    {
        while($count = $this->eventScalar->get()) {
            for ($i = 0; $i < $count; $i++) {
                $event = $this->events->poll();
                switch ($event->type) {
                    case Events\Event\Type::Cancel:
                    case Events\Event\Type::Kill:
                    case Events\Event\Type::Error:
                        if(isset($this->futures[$event->source])) {
                            $this->futures[$event->source]->onEvent($event);
                            unset($this->futures[$event->source]);
                            unset($this->threads[$event->source]);
                            $this->events->remove($event->source);
                        }
                        break;
                    case Events\Event\Type::Read:
                        if($event->object instanceof \parallel\Future) {
                            $name = $event->source;
                            if($this->futures[$name] ?? null) {
                                try {
                                    $this->futures[$name]->resolve();
                                } catch (Throwable) {
                                } finally {
                                    unset($this->futures[$name]);
                                    unset($this->threads[$name]);
                                    $this->counterChannel->send(-1);
                                }
                            }
                        }
                        break;
                }
            }
        }
    }

    /*** @var string */
    private string $signalHandlerId;

    /**
     * @param Future $future
     * @param string $name
     * @return void
     */
    public function listenFuture(Future $future, string $name): void
    {
        $this->futures[$name] = $future;
        $this->events->addFuture($name, $future->future);
    }

    /**
     * @return void
     */
    private function registerSignalHandler(): void
    {
        if(isset($this->signalHandlerId)) {
            return;
        }

        try {
            $this->signalHandlerId = onSignal(SIGUSR2, fn () => $this->poll());
        } catch (UnsupportedFeatureException) {
        }

        $this->observerId = repeat(function (Closure $cancel) {
            if(empty($this->threads)) {
                $cancel();
                $this->unregisterSignalHandler();
            }
        }, 1);
    }

    /**
     * @return void
     */
    private function unregisterSignalHandler(): void
    {
        if(!isset($this->signalHandlerId)) {
            return;
        }

        cancel($this->signalHandlerId);
        unset($this->signalHandlerId);

        cancel($this->observerId);
        unset($this->observerId);
    }

    /**
     * @return void
     */
    private function registerForkHandler(): void
    {
        registerForkHandler(function () {
            /*** @var LibraryAbstract $instance*/
            /*** @var int $cpuCount*/
            /*** @var string $autoload*/
            /*** @var Events $events*/
            /*** @var Thread[] $threads*/
            /*** @var Future[] $futures*/
            /*** @var int $index*/
            /*** @var Runtime $counterRuntime*/
            /*** @var \parallel\Future $counterFuture */
            /*** @var Channel $counterChannel */
            /*** @var Sync $eventScalar */
            /*** @var Sync $initScalar */

            foreach ($this->threads as $key => $thread) {
                $thread->kill();
                $this->futures[$key]?->cancel();
                unset($this->threads[$key]);
                unset($this->futures[$key]);
                $this->events->remove($key);
            }
            $this->events->remove('future');
            $this->initialize();
        });
    }

    public function __destruct()
    {
        $this->counterChannel->send(-1);
    }

    /**
     * @param Closure $closure
     * @return Thread
     */
    public function thread(Closure $closure): Thread
    {
        $this->registerSignalHandler();
        $name = strval($this->index++);
        $thread = new Thread($closure, $name);
        $this->threads[$name] = $thread;
        return $thread;
    }

    /**
     * @param string $name
     * @return Channel
     */
    public function openChannel(string $name): Channel
    {
        return new Channel(\parallel\Channel::make($name));
    }

    /**
     * @param string   $name
     * @param int|null $capacity
     * @return Channel
     */
    public function makeChannel(string $name, ?int $capacity = null): Channel
    {
        return is_int($capacity)
            ? new Channel(\parallel\Channel::make($name, $capacity))
            : new Channel(\parallel\Channel::make($name));
    }
}
