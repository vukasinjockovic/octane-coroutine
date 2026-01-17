<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Swoole\Coroutine\Channel;

class ChannelPoolLock implements PoolLock
{
    public function __construct(private Channel $channel)
    {
        $this->channel->push(true);
    }

    public function acquire(float $timeout): bool
    {
        return $this->channel->pop($timeout) !== false;
    }

    public function release(): void
    {
        $this->channel->push(true);
    }
}
