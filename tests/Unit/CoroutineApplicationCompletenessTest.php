<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CoroutineApplicationCompletenessTest extends TestCase
{
    public function test_proxy_overrides_all_public_methods(): void
    {
        $appMethods = $this->publicMethods(Application::class);
        $containerMethods = $this->publicMethods(Container::class);
        $proxyMethods = $this->publicMethods(CoroutineApplication::class);

        $missing = [];
        foreach (array_keys($appMethods + $containerMethods) as $method) {
            if (!isset($proxyMethods[$method])) {
                $missing[] = $method;
            }
        }

        sort($missing);

        $this->assertSame([], $missing);
    }

    protected function publicMethods(string $class): array
    {
        $ref = new ReflectionClass($class);
        $methods = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $methods[$method->getName()] = true;
        }

        return $methods;
    }
}
