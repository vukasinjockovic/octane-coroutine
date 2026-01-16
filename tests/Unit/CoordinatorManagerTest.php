<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laravel\Octane\Swoole\Coroutine\CoordinatorManager;
use Laravel\Octane\Swoole\Coroutine\Coordinator;

/**
 * Tests for the CoordinatorManager class.
 * Used for worker lifecycle coordination.
 */
class CoordinatorManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CoordinatorManager::clearAll();
    }

    protected function tearDown(): void
    {
        CoordinatorManager::clearAll();
        parent::tearDown();
    }

    public function test_until_returns_coordinator_instance()
    {
        $coordinator = CoordinatorManager::until(CoordinatorManager::WORKER_START);

        $this->assertInstanceOf(Coordinator::class, $coordinator);
    }

    public function test_until_returns_same_instance_for_same_identifier()
    {
        $coordinator1 = CoordinatorManager::until(CoordinatorManager::WORKER_START);
        $coordinator2 = CoordinatorManager::until(CoordinatorManager::WORKER_START);

        $this->assertSame($coordinator1, $coordinator2);
    }

    public function test_until_returns_different_instances_for_different_identifiers()
    {
        $coordinator1 = CoordinatorManager::until(CoordinatorManager::WORKER_START);
        $coordinator2 = CoordinatorManager::until(CoordinatorManager::WORKER_EXIT);

        $this->assertNotSame($coordinator1, $coordinator2);
    }

    public function test_has_returns_true_for_registered_coordinator()
    {
        CoordinatorManager::until(CoordinatorManager::WORKER_START);

        $this->assertTrue(CoordinatorManager::has(CoordinatorManager::WORKER_START));
    }

    public function test_has_returns_false_for_unregistered_coordinator()
    {
        $this->assertFalse(CoordinatorManager::has('nonexistent'));
    }

    public function test_clear_removes_specific_coordinator()
    {
        CoordinatorManager::until(CoordinatorManager::WORKER_START);
        CoordinatorManager::until(CoordinatorManager::WORKER_EXIT);

        CoordinatorManager::clear(CoordinatorManager::WORKER_START);

        $this->assertFalse(CoordinatorManager::has(CoordinatorManager::WORKER_START));
        $this->assertTrue(CoordinatorManager::has(CoordinatorManager::WORKER_EXIT));
    }

    public function test_clear_all_removes_all_coordinators()
    {
        CoordinatorManager::until(CoordinatorManager::WORKER_START);
        CoordinatorManager::until(CoordinatorManager::WORKER_EXIT);
        CoordinatorManager::until(CoordinatorManager::WORKER_ERROR);

        CoordinatorManager::clearAll();

        $this->assertFalse(CoordinatorManager::has(CoordinatorManager::WORKER_START));
        $this->assertFalse(CoordinatorManager::has(CoordinatorManager::WORKER_EXIT));
        $this->assertFalse(CoordinatorManager::has(CoordinatorManager::WORKER_ERROR));
    }

    public function test_get_registered_returns_all_identifiers()
    {
        CoordinatorManager::until(CoordinatorManager::WORKER_START);
        CoordinatorManager::until(CoordinatorManager::WORKER_EXIT);

        $registered = CoordinatorManager::getRegistered();

        $this->assertContains(CoordinatorManager::WORKER_START, $registered);
        $this->assertContains(CoordinatorManager::WORKER_EXIT, $registered);
    }

    public function test_defined_constants_exist()
    {
        $this->assertEquals('worker.start', CoordinatorManager::WORKER_START);
        $this->assertEquals('worker.exit', CoordinatorManager::WORKER_EXIT);
        $this->assertEquals('worker.error', CoordinatorManager::WORKER_ERROR);
        $this->assertEquals('request.start', CoordinatorManager::REQUEST_START);
        $this->assertEquals('request.end', CoordinatorManager::REQUEST_END);
    }
}
