<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Facade;
use Laravel\Octane\Swoole\Coroutine\FacadeCache;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

class FacadeCacheTest extends TestCase
{
    public function test_disable_turns_off_facade_caching(): void
    {
        $facadeRef = new ReflectionClass(Facade::class);
        $cachedProp = $facadeRef->getProperty('cached');
        $cachedProp->setAccessible(true);

        $flagProp = new ReflectionProperty(FacadeCache::class, 'disabled');
        $flagProp->setAccessible(true);

        $originalCached = $cachedProp->getValue();
        $originalFlag = $flagProp->getValue();

        try {
            $cachedProp->setValue(null, true);
            $flagProp->setValue(null, false);

            FacadeCache::disable();

            $this->assertFalse($cachedProp->getValue());
            $this->assertTrue($flagProp->getValue());
        } finally {
            $cachedProp->setValue(null, $originalCached);
            $flagProp->setValue(null, $originalFlag);
        }
    }
}
