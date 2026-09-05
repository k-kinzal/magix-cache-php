<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MemoryCache;

#[CoversClass(CacheRuntimeRegistry::class)]
#[UsesClass(CacheRuntime::class)]
#[UsesClass(\Magix\Cache\Runtime\Extension\RegisteredExtensions::class)]
#[UsesClass(\Magix\Cache\Clock\SystemClock::class)]
final class CacheRuntimeRegistryTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        CacheRuntimeRegistry::reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        CacheRuntimeRegistry::reset();
    }

    public function testResolveReturnsTheRegisteredRuntime(): void
    {
        $runtime = new CacheRuntime(new MemoryCache());
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, $runtime);

        self::assertSame($runtime, CacheRuntimeRegistry::resolve(CacheRuntimeRegistry::DEFAULT_NAME));
    }

    public function testResolveInvokesAProviderOnEveryResolution(): void
    {
        $resolutions = 0;
        CacheRuntimeRegistry::register('scoped', static function () use (&$resolutions): CacheRuntime {
            ++$resolutions;

            return new CacheRuntime(new MemoryCache());
        });

        CacheRuntimeRegistry::resolve('scoped');
        CacheRuntimeRegistry::resolve('scoped');

        self::assertSame(2, $resolutions);
    }

    public function testRegisterFixesEachReferenceName(): void
    {
        $first = new CacheRuntime(new MemoryCache());
        $second = new CacheRuntime(new MemoryCache());
        CacheRuntimeRegistry::register('first', $first);
        CacheRuntimeRegistry::register('second', $second);

        self::assertSame($first, CacheRuntimeRegistry::resolve('first'));
        self::assertSame($second, CacheRuntimeRegistry::resolve('second'));
    }

    public function testIsRegisteredObservesTheBootstrapLifecycle(): void
    {
        self::assertFalse(CacheRuntimeRegistry::isRegistered(CacheRuntimeRegistry::DEFAULT_NAME));

        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache()));

        self::assertTrue(CacheRuntimeRegistry::isRegistered(CacheRuntimeRegistry::DEFAULT_NAME));
    }

    public function testResetClearsEveryRegistration(): void
    {
        CacheRuntimeRegistry::register('scoped', new CacheRuntime(new MemoryCache()));

        CacheRuntimeRegistry::reset();

        self::assertFalse(CacheRuntimeRegistry::isRegistered('scoped'));
    }
}
