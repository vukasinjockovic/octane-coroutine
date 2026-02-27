<?php

namespace Laravel\Octane\Swoole\Database;

use SplObjectStorage;
use Swoole\Coroutine\Channel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Connection pool for Laravel using Swoole Channels for coroutine-safe pooling.
 */
class DatabasePool
{
    protected Channel $channel;
    protected int $currentConnections = 0;
    protected array $config;
    protected string $name;
    protected ConnectionFactory $factory;
    protected array $connectionConfig;

    /**
     * Tracks the last time each connection was used (unix timestamp).
     * Used for time-based health checks: only run SELECT 1 on connections
     * that have been idle longer than $idleCheckThreshold seconds.
     */
    protected SplObjectStorage $lastUsedAt;

    /**
     * Seconds a connection can sit idle before we verify it with SELECT 1.
     * Connections used within this window are trusted to be valid.
     */
    protected int $idleCheckThreshold = 30;

    public function __construct(array $config, array $connectionConfig, string $name, ConnectionFactory $factory)
    {
        $this->config = $config;
        $this->name = $name;
        $this->factory = $factory;
        $this->connectionConfig = $connectionConfig;
        $this->lastUsedAt = new SplObjectStorage();

        // Create a channel for pooling connections
        // Channel size = max_connections
        $maxConnections = $config['max_connections'] ?? 10;
        $this->channel = new Channel($maxConnections);

        // Pre-create minimum connections
        $minConnections = $config['min_connections'] ?? 1;
        for ($i = 0; $i < $minConnections; $i++) {
            try {
                $this->channel->push($this->createConnection());
            } catch (Throwable $e) {
                // error_log("Failed to create initial pool connection: " . $e->getMessage());
            }
        }
    }

    /**
     * Get a connection from the pool
     */
    public function get()
    {
        $waitTimeout = $this->config['wait_timeout'] ?? 3.0;
        $maxConnections = $this->config['max_connections'] ?? 10;

        // Fast path: try a non-blocking pop first to avoid unnecessary waits.
        $connection = $this->channel->pop(0.001);

        if ($connection === false) {
            // If we can grow the pool, create immediately instead of waiting.
            if ($this->currentConnections < $maxConnections) {
                $connection = $this->createConnection();
            } else {
                // Pool is at max; wait for a connection to be released.
                $connection = $this->channel->pop($waitTimeout);

                if ($connection === false) {
                    throw new \RuntimeException('Connection pool exhausted. Cannot establish new connection before wait_timeout.');
                }
            }
        }

        // Time-based health check: only verify connections idle > threshold seconds.
        // Recently used connections are trusted to be valid, avoiding a SELECT 1
        // round-trip (~0.5-4ms) on every borrow.
        $lastUsed = $this->lastUsedAt->contains($connection)
            ? $this->lastUsedAt[$connection]
            : 0;

        if ((time() - $lastUsed) > $this->idleCheckThreshold) {
            if (!$this->checkConnection($connection)) {
                $connection = $this->reconnect($connection);
            }
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool
     */
    public function release($connection): void
    {
        if (!$connection) {
            return;
        }

        try {
            $this->resetConnection($connection);

            // Track when this connection was last used for time-based health checks
            $this->lastUsedAt[$connection] = time();

            $pushTimeout = $this->config['release_timeout'] ?? 1.0;
            $pushed = $this->channel->push($connection, $pushTimeout);

            if (!$pushed) {
                // error_log("DB pool release timeout - closing connection instead");
                $this->lastUsedAt->detach($connection);
                $this->closeConnection($connection);
                $this->currentConnections--;
            }
        } catch (Throwable $e) {
            // error_log("Error releasing connection to pool: " . $e->getMessage());
            // Try to close the connection to prevent leaks
            $this->lastUsedAt->detach($connection);
            $this->closeConnection($connection);
            $this->currentConnections--;
        }
    }

    /**
     * Reset connection state to prevent state leaks between requests.
     *
     * Only rolls back open transactions and flushes the query log.
     * Driver-specific session resets (RESET ALL for PostgreSQL, SET SESSION
     * for MySQL) were removed to eliminate unnecessary DB round-trips on
     * every connection release. Pooled connections maintain consistent
     * session state because Laravel does not modify session-level settings
     * during normal request handling.
     */
    protected function resetConnection($connection): void
    {
        try {
            if ($connection instanceof Connection) {
                // Roll back any open transaction
                $pdo = $connection->getPdo();

                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Reset the query log
                $connection->flushQueryLog();
            }
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Safely close a connection
     */
    protected function closeConnection($connection): void
    {
        try {
            if ($connection instanceof Connection) {
                $connection->disconnect();
            }
        } catch (Throwable $e) {
            // error_log("Error closing connection: " . $e->getMessage());
        }
    }

    /**
     * Create a new database connection
     */
    protected function createConnection()
    {
        $this->currentConnections++;
        return $this->factory->make($this->connectionConfig, $this->name);
    }

    /**
     * Check if a connection is still valid
     */
    protected function checkConnection($connection): bool
    {
        try {
            // Ping the database
            $connection->getPdo()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Reconnect a stale connection
     */
    protected function reconnect($connection)
    {
        try {
            $connection->reconnect();
            return $connection;
        } catch (Throwable $e) {
            // If reconnect fails, create a new one
            $this->currentConnections--;
            return $this->createConnection();
        }
    }

    /**
     * Flush idle connections from the pool
     */
    public function flush(): void
    {
        $minConnections = $this->config['min_connections'] ?? 1;

        while ($this->currentConnections > $minConnections) {
            $connection = $this->channel->pop(0.001);

            if ($connection === false) {
                break; // No more connections in channel
            }

            $this->lastUsedAt->detach($connection);
            $this->closeConnection($connection);
            $this->currentConnections--;
        }
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return [
            'current_connections' => $this->currentConnections,
            'available_connections' => $this->channel->length(),
            'max_connections' => $this->config['max_connections'] ?? 10,
            'min_connections' => $this->config['min_connections'] ?? 1,
        ];
    }
}
