<?php

declare(strict_types=1);

namespace Tests\Package\Laravel\Unit;

use Illuminate\Contracts\Foundation\Application;
use Magix\Cache\Cache\PSR16\SimpleCache;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Laravel\MagixCacheServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[CoversClass(MagixCacheServiceProvider::class)]
#[UsesClass(SimpleCache::class)]
#[UsesClass(CacheRuntime::class)]
final class MagixCacheServiceProviderTest extends TestCase
{
    public function testRegisterDefinesStoreAndRuntimeSingletons(): void
    {
        $application = $this->createMock(Application::class);
        $application->expects(self::once())->method('singleton');

        (new MagixCacheServiceProvider($application))->register();
    }

    public function testBootInstallsRuntime(): void
    {
        $application = self::createStub(Application::class);
        $runtime = new CacheRuntime(new SimpleCache(new Psr16Cache(new ArrayAdapter())));

        (new MagixCacheServiceProvider($application))->boot($runtime);

        self::assertSame($runtime, CacheRuntime::current());
        CacheRuntime::setCurrent(null);
    }
}
