<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\FacadeCache;
use PHPUnit\Framework\TestCase;

class FacadeCoroutineIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_facades_resolve_from_coroutine_app_context()
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        FacadeCache::disable();

        $baseApp = new Application(__DIR__);
        $proxy = new CoroutineApplication($baseApp);

        Facade::setFacadeApplication($proxy);
        Facade::clearResolvedInstances();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            \Swoole\Coroutine::create(function () use (&$results) {
                $app = new Application(__DIR__);
                $app->instance('test.facade', new CoroutineFacadeService('alpha'));

                Context::set('octane.app', $app);
                $results[] = CoroutineFacade::value();
            });

            \Swoole\Coroutine::create(function () use (&$results) {
                $app = new Application(__DIR__);
                $app->instance('test.facade', new CoroutineFacadeService('bravo'));

                Context::set('octane.app', $app);
                $results[] = CoroutineFacade::value();
            });
        });

        sort($results);
        $this->assertSame(['alpha', 'bravo'], $results);
    }
}

class CoroutineFacadeService
{
    public function __construct(private string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }
}

class CoroutineFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'test.facade';
    }
}
