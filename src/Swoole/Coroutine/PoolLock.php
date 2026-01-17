<?php

namespace Laravel\Octane\Swoole\Coroutine;

interface PoolLock
{
    public function acquire(float $timeout): bool;

    public function release(): void;
}
