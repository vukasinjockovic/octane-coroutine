<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\Coroutine\ChannelPoolLock;
use Laravel\Octane\Swoole\Coroutine\WorkerPool;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;

class WorkerPoolTest extends TestCase
{
    public function test_acquire_grows_pool_when_empty_and_below_max(): void
    {
        $results = [];

        $this->runCoroutine(function () use (&$results) {
            $created = 0;
            $pool = new WorkerPool(
                new Channel(2),
                1,
                2,
                function (int $index) use (&$created): TestWorker {
                    $created++;

                    return new TestWorker($index);
                },
                new ChannelPoolLock(new Channel(1))
            );

            $pool->seed(1);

            $results['worker1'] = $pool->acquire(0.001, true);
            $results['worker2'] = $pool->acquire(0.001, true);
            $results['stats'] = $pool->stats();
            $results['created'] = $created;
        });

        $this->assertInstanceOf(TestWorker::class, $results['worker1']);
        $this->assertInstanceOf(TestWorker::class, $results['worker2']);
        $this->assertSame(2, $results['created']);
        $this->assertSame(2, $results['stats']['current_size']);
    }

    public function test_acquire_returns_null_when_at_max_and_reject_on_full(): void
    {
        $results = [];

        $this->runCoroutine(function () use (&$results) {
            $pool = new WorkerPool(
                new Channel(2),
                1,
                2,
                fn (int $index) => new TestWorker($index),
                new ChannelPoolLock(new Channel(1))
            );

            $pool->seed(2);

            $results['worker1'] = $pool->acquire(0.001, true);
            $results['worker2'] = $pool->acquire(0.001, true);
            $results['worker3'] = $pool->acquire(0.001, true);
            $results['stats'] = $pool->stats();
        });

        $this->assertInstanceOf(TestWorker::class, $results['worker1']);
        $this->assertInstanceOf(TestWorker::class, $results['worker2']);
        $this->assertNull($results['worker3']);
        $this->assertSame(2, $results['stats']['current_size']);
        $this->assertSame(0, $results['stats']['available']);
    }

    public function test_acquire_shrinks_pool_when_idle_above_min(): void
    {
        $results = [];

        $this->runCoroutine(function () use (&$results) {
            $pool = new WorkerPool(
                new Channel(3),
                1,
                3,
                fn (int $index) => new TestWorker($index),
                new ChannelPoolLock(new Channel(1)),
                1
            );

            $pool->seed(3);

            \Swoole\Coroutine::sleep(1.1);

            $workerA = $pool->acquire(0.001, true);
            $results['stats_after_acquire'] = $pool->stats();

            $results['kept'] = $pool->release($workerA);
            $results['stats'] = $pool->stats();
        });

        $this->assertTrue($results['kept']);
        $this->assertSame(1, $results['stats_after_acquire']['current_size']);
        $this->assertSame(1, $results['stats']['current_size']);
        $this->assertSame(1, $results['stats']['available']);
    }

    public function test_release_keeps_worker_when_at_min_size(): void
    {
        $results = [];

        $this->runCoroutine(function () use (&$results) {
            $pool = new WorkerPool(
                new Channel(1),
                1,
                1,
                fn (int $index) => new TestWorker($index),
                new ChannelPoolLock(new Channel(1))
            );

            $pool->seed(1);

            $worker = $pool->acquire(0.001, true);

            $results['kept'] = $pool->release($worker);
            $results['stats'] = $pool->stats();
        });

        $this->assertTrue($results['kept']);
        $this->assertSame(1, $results['stats']['current_size']);
        $this->assertSame(1, $results['stats']['available']);
    }

    private function runCoroutine(callable $callback): void
    {
        if (! class_exists(\Swoole\Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        \Swoole\Coroutine\run($callback);
    }
}

class TestWorker
{
    public function __construct(public int $poolIndex)
    {
    }
}
