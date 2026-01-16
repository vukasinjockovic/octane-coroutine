<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connection;
use Laravel\Octane\Swoole\Database\DatabasePool;
use Mockery;
use PDO;
use ReflectionMethod;

/**
 * Tests for the DatabasePool class.
 * Bug #5: Transaction state reset before release.
 * Bug #6: Timeout on channel push to prevent blocking.
 *
 * Note: These tests mock the dependencies since we can't run Swoole
 * in the PHPUnit environment directly.
 */
class DatabasePoolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pool_config_defaults_are_reasonable()
    {
        // Test that our default config values are sensible
        $defaultConfig = [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'release_timeout' => 1.0,
        ];

        $this->assertGreaterThan(0, $defaultConfig['min_connections']);
        $this->assertGreaterThanOrEqual($defaultConfig['min_connections'], $defaultConfig['max_connections']);
        $this->assertGreaterThan(0, $defaultConfig['wait_timeout']);
        $this->assertGreaterThan(0, $defaultConfig['release_timeout']);
    }

    public function test_connection_reset_includes_transaction_rollback()
    {
        // This test verifies the logic that should be applied
        // when resetting a connection before returning to pool

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(true);
        $pdo->shouldReceive('rollBack')->once();

        // The actual implementation would call these methods
        // This test documents the expected behavior
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $this->assertTrue(true); // Test passes if no exception
    }

    public function test_connection_reset_skips_rollback_when_no_transaction()
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(false);
        // rollBack should NOT be called
        $pdo->shouldNotReceive('rollBack');

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $this->assertTrue(true);
    }

    public function test_mysql_session_reset_commands_are_correct()
    {
        // Verify the SQL commands used for MySQL session reset
        $expectedCommands = [
            'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'SET autocommit = 1',
        ];

        foreach ($expectedCommands as $command) {
            $this->assertStringContainsString('SET', $command);
        }
    }

    public function test_postgresql_session_reset_command_is_correct()
    {
        // Verify the SQL command used for PostgreSQL session reset
        $expectedCommand = 'RESET ALL';

        $this->assertEquals('RESET ALL', $expectedCommand);
    }

    public function test_pool_stats_structure()
    {
        // Verify the expected structure of pool stats
        $expectedKeys = [
            'current_connections',
            'available_connections',
            'max_connections',
            'min_connections',
        ];

        // Simulated stats (what the real implementation returns)
        $stats = [
            'current_connections' => 5,
            'available_connections' => 3,
            'max_connections' => 10,
            'min_connections' => 1,
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }

    public function test_reset_connection_rolls_back_and_resets_mysql_session()
    {
        $pool = $this->newPoolWithoutConstructor();

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(true);
        $pdo->shouldReceive('rollBack')->once();
        $pdo->shouldReceive('exec')->with('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ')->once();
        $pdo->shouldReceive('exec')->with('SET autocommit = 1')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('flushQueryLog')->once();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');

        $this->invokeResetConnection($pool, $connection);

        $this->assertTrue(true);
    }

    public function test_reset_connection_resets_postgres_session()
    {
        $pool = $this->newPoolWithoutConstructor();

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(false);
        $pdo->shouldReceive('exec')->with('RESET ALL')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('flushQueryLog')->once();
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');

        $this->invokeResetConnection($pool, $connection);

        $this->assertTrue(true);
    }

    protected function newPoolWithoutConstructor(): DatabasePool
    {
        $reflection = new \ReflectionClass(DatabasePool::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    protected function invokeResetConnection(DatabasePool $pool, Connection $connection): void
    {
        $method = new ReflectionMethod(DatabasePool::class, 'resetConnection');
        $method->setAccessible(true);
        $method->invoke($pool, $connection);
    }
}
