<?php

declare(strict_types=1);

namespace Tests\Package\Symfony\Unit;

use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Magix\Cache\Symfony\MagixCacheBundle;
use Override;
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
#[UsesClass(CacheRuntimeRegistry::class)]
#[UsesClass(\Magix\Cache\Runtime\Extension\RegisteredExtensions::class)]
final class MagixCacheBundleTest extends TestCase
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

    public function testBootFixesTheDefaultRuntimeReferenceToAContainerProvider(): void
    {
        $container = new ContainerBuilder();
        $runtime = new CacheRuntime(new MemoryCache());
        $container->set(CacheRuntime::class, $runtime);
        $bundle = new MagixCacheBundle();
        $bundle->setContainer($container);

        $bundle->boot();

        self::assertTrue(CacheRuntimeRegistry::isRegistered(CacheRuntimeRegistry::DEFAULT_NAME));
        self::assertSame($runtime, CacheRuntimeRegistry::resolve(CacheRuntimeRegistry::DEFAULT_NAME));
    }

    public function testBootLeavesAnAlreadyFixedDefaultReferenceUntouched(): void
    {
        $fixed = new CacheRuntime(new MemoryCache());
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, $fixed);
        $bundle = new MagixCacheBundle();
        $bundle->setContainer(new ContainerBuilder());

        $bundle->boot();

        self::assertSame($fixed, CacheRuntimeRegistry::resolve(CacheRuntimeRegistry::DEFAULT_NAME));
    }
}
