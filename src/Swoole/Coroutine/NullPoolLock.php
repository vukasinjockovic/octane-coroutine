<?php

namespace Laravel\Octane\Swoole\Coroutine;

class NullPoolLock implements PoolLock
{
    public function acquire(float $timeout): bool
    {
        return true;
    }

    public function release(): void
    {
    }
}
