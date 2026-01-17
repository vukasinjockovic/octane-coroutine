<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Laravel\Octane\Listeners\GiveNewRequestInstanceToPaginator;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use PHPUnit\Framework\TestCase;

class PaginationStateCoroutineTest extends TestCase
{
    private ?Container $originalContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Context::clear();
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    public function test_pagination_resolvers_use_coroutine_app_context(): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = new Application(__DIR__);
        $proxy = new CoroutineApplication($base);
        Container::setInstance($proxy);

        $listener = new GiveNewRequestInstanceToPaginator();
        $event = new class($base) {
            public function __construct(public $sandbox)
            {
            }
        };

        $listener->handle($event);

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            \Swoole\Coroutine::create(function () use (&$results) {
                $sandbox = new Application(__DIR__);
                $sandbox->instance('request', Request::create('/?page=2'));
                Context::set('octane.app', $sandbox);
                $results[] = Paginator::resolveCurrentPage('page');
            });

            \Swoole\Coroutine::create(function () use (&$results) {
                $sandbox = new Application(__DIR__);
                $sandbox->instance('request', Request::create('/?page=7'));
                Context::set('octane.app', $sandbox);
                $results[] = Paginator::resolveCurrentPage('page');
            });
        });

        sort($results);
        $this->assertSame([2, 7], $results);
    }
}
