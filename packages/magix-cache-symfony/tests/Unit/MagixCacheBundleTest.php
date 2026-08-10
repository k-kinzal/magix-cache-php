<?php

declare(strict_types=1);

namespace Tests\Package\Symfony\Unit;

use LogicException;
use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Symfony\MagixCacheBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tests\Fixture\MemoryCache;

#[CoversClass(MagixCacheBundle::class)]
#[UsesClass(CacheItemPool::class)]
#[UsesClass(CacheRuntime::class)]
final class MagixCacheBundleTest extends TestCase
{
    public function testLoadExtensionRegistersStoreAndRuntime(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('cache.app', (new Definition(ArrayAdapter::class))->setPublic(true));
        $bundle = new MagixCacheBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([], $container);

        self::assertTrue($container->hasDefinition(CacheItemPool::class));
        self::assertTrue($container->hasDefinition(CacheRuntime::class));
    }

    public function testBootInstallsContainerRuntime(): void
    {
        $container = new ContainerBuilder();
        $runtime = new CacheRuntime(new MemoryCache());
        $container->set(CacheRuntime::class, $runtime);
        $bundle = new MagixCacheBundle();
        $bundle->setContainer($container);

        $bundle->boot();

        self::assertSame($runtime, CacheRuntime::current());
        CacheRuntime::setCurrent(null);
    }

    public function testShutdownRemovesRuntime(): void
    {
        CacheRuntime::setCurrent(new CacheRuntime(new MemoryCache()));
        (new MagixCacheBundle())->shutdown();

        $this->expectException(LogicException::class);
        CacheRuntime::current();
    }
}
