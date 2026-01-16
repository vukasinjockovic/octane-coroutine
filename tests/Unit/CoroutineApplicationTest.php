<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Foundation\Application;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;

class CoroutineApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();
        parent::tearDown();
    }

    public function test_make_uses_base_app_outside_coroutine(): void
    {
        $base = new Application(__DIR__);
        $base->instance('test.value', 'base');

        $proxy = new CoroutineApplication($base);

        $this->assertSame('base', $proxy->make('test.value'));
    }

    public function test_make_uses_context_app_inside_coroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = new Application(__DIR__);
        $base->instance('test.value', 'base');

        $sandbox = new Application(__DIR__);
        $sandbox->instance('test.value', 'sandbox');

        $proxy = new CoroutineApplication($base);
        $result = null;

        \Swoole\Coroutine\run(function () use ($sandbox, $proxy, &$result) {
            Context::set('octane.app', $sandbox);
            $result = $proxy->make('test.value');
        });

        $this->assertSame('sandbox', $result);
    }
}
