<?php

declare(strict_types=1);

namespace Tests\Package\Laravel\Unit;

use Illuminate\Contracts\Foundation\Application;
use Magix\Cache\Cache\PSR16\SimpleCache;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Laravel\MagixCacheServiceProvider;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[CoversClass(MagixCacheServiceProvider::class)]
#[UsesClass(SimpleCache::class)]
#[UsesClass(CacheRuntime::class)]
#[UsesClass(CacheRuntimeRegistry::class)]
#[UsesClass(\Magix\Cache\Runtime\Extension\RegisteredExtensions::class)]
final class MagixCacheServiceProviderTest extends TestCase
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

    public function testRegisterDefinesStoreAndRuntimeSingletons(): void
    {
        $application = $this->createMock(Application::class);
        $application->expects(self::once())->method('singleton');

        (new MagixCacheServiceProvider($application))->register();
    }

    public function testBootFixesTheDefaultRuntimeReferenceToAContainerProvider(): void
    {
        $runtime = new CacheRuntime(new SimpleCache(new Psr16Cache(new ArrayAdapter())));
        $application = self::createStub(Application::class);
        $application->method('make')->willReturn($runtime);

        (new MagixCacheServiceProvider($application))->boot();

        self::assertTrue(CacheRuntimeRegistry::isRegistered(CacheRuntimeRegistry::DEFAULT_NAME));
        self::assertSame($runtime, CacheRuntimeRegistry::resolve(CacheRuntimeRegistry::DEFAULT_NAME));
    }

    public function testBootLeavesAnAlreadyFixedDefaultReferenceUntouched(): void
    {
        $fixed = new CacheRuntime(new SimpleCache(new Psr16Cache(new ArrayAdapter())));
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, $fixed);
        $application = self::createStub(Application::class);

        (new MagixCacheServiceProvider($application))->boot();

        self::assertSame($fixed, CacheRuntimeRegistry::resolve(CacheRuntimeRegistry::DEFAULT_NAME));
    }
}
