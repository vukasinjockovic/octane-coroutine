<?php

namespace Laravel\Octane\Swoole\Redis;

use Illuminate\Redis\RedisManager;
use Laravel\Octane\Swoole\Coroutine\Context;

use function Illuminate\Support\enum_value;

class CoroutineRedisManager extends RedisManager
{
    /**
     * Get a Redis connection by name.
     *
     * In coroutine mode, each coroutine gets its own connection stored in Context
     * to prevent RESP protocol interleaving when multiple coroutines share a socket.
     */
    public function connection($name = null)
    {
        $name = enum_value($name) ?: 'default';

        if (!Context::inCoroutine()) {
            return parent::connection($name);
        }

        $contextKey = "redis.connection.{$name}";
        $connection = Context::get($contextKey);

        if ($connection) {
            return $connection;
        }

        $connection = $this->configure($this->resolve($name), $name);

        Context::set($contextKey, $connection);

        return $connection;
    }

    /**
     * Release all Redis connections for the current coroutine.
     * Called from Worker::handle() finally block before Context::clear().
     */
    public function releaseConnections(): void
    {
        if (!Context::inCoroutine()) {
            return;
        }

        $allContext = Context::all();

        foreach ($allContext as $key => $value) {
            if (!str_starts_with($key, 'redis.connection.')) {
                continue;
            }

            try {
                $value->client()->disconnect();
            } catch (\Throwable $e) {
                // Silently ignore disconnect errors — connection may already be closed
            }

            Context::delete($key);
        }
    }

    /**
     * Disconnect the given connection and remove from local cache.
     *
     * In coroutine mode, purge from Context instead of parent's $connections array.
     */
    public function purge($name = null)
    {
        $name = $name ?: 'default';

        if (Context::inCoroutine()) {
            $contextKey = "redis.connection.{$name}";
            $connection = Context::get($contextKey);

            if ($connection) {
                try {
                    $connection->client()->disconnect();
                } catch (\Throwable $e) {
                    // ignore
                }
                Context::delete($contextKey);
            }

            return;
        }

        parent::purge($name);
    }
}
