<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Closure;
use Swoole\Coroutine\Channel;

class WorkerPool
{
    protected int $currentSize = 0;
    protected int $nextIndex = 0;
    protected array $metadata = [];
    protected Closure $factory;

    public function __construct(
        protected Channel $channel,
        protected int $minSize,
        protected int $maxSize,
        callable $factory,
        protected PoolLock $lock = new NullPoolLock
    ) {
        $this->factory = Closure::fromCallable($factory);
        $this->minSize = max(0, $this->minSize);
        $this->maxSize = max($this->minSize, $this->maxSize);
    }

    public function seed(int $count): void
    {
        $seedCount = max(0, min($this->maxSize, $count));

        for ($i = 0; $i < $seedCount; $i++) {
            $worker = $this->createWorker();

            if (! $worker) {
                continue;
            }

            $this->channel->push($worker);
        }
    }

    public function acquire(float $waitTimeout, bool $rejectOnFull): ?object
    {
        $worker = $this->channel->pop(0.001);

        if ($worker === false && $this->currentSize < $this->maxSize) {
            $worker = $this->grow();
        }

        if (($worker === false || $worker === null) && ! $rejectOnFull && $waitTimeout > 0) {
            $worker = $this->channel->pop($waitTimeout);
        }

        return $worker === false ? null : $worker;
    }

    public function release(object $worker): bool
    {
        $workerId = spl_object_id($worker);

        if (isset($this->metadata[$workerId])) {
            $this->metadata[$workerId]['last_used_at'] = microtime(true);
        }

        $available = $this->channel->length();

        if ($this->currentSize > $this->minSize && $available >= $this->minSize) {
            $this->currentSize--;
            unset($this->metadata[$workerId]);

            return false;
        }

        $pushed = $this->channel->push($worker, 0.001);

        if (! $pushed) {
            $this->currentSize = max($this->minSize, $this->currentSize - 1);
            unset($this->metadata[$workerId]);

            return false;
        }

        return true;
    }

    public function stats(): array
    {
        return [
            'current_size' => $this->currentSize,
            'available' => $this->channel->length(),
            'min_size' => $this->minSize,
            'max_size' => $this->maxSize,
            'next_index' => $this->nextIndex,
        ];
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    protected function grow(): ?object
    {
        if (! $this->lock->acquire(0.001)) {
            return null;
        }

        try {
            if ($this->currentSize >= $this->maxSize) {
                return null;
            }

            return $this->createWorker();
        } finally {
            $this->lock->release();
        }
    }

    protected function createWorker(): ?object
    {
        $poolIndex = $this->nextIndex;
        $worker = ($this->factory)($poolIndex);

        if (! is_object($worker)) {
            return null;
        }

        $timestamp = microtime(true);
        $this->metadata[spl_object_id($worker)] = [
            'pool_index' => $poolIndex,
            'created_at' => $timestamp,
            'last_used_at' => $timestamp,
        ];

        $this->currentSize++;
        $this->nextIndex++;

        return $worker;
    }
}
