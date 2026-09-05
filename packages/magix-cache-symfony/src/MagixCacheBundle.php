<?php

declare(strict_types=1);

namespace Magix\Cache\Symfony;

use LogicException;
use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Connects MagixCache to Symfony's cache.app PSR-6 pool.
 */
final class MagixCacheBundle extends AbstractBundle
{
    /**
     * Registers the runtime against Symfony's PSR-6 cache.app pool.
     *
     * @param array<array-key, mixed> $config
     */
    #[Override]
    public function loadExtension(
        array $config,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        $services = $configurator->services();
        $services
            ->set(CacheItemPool::class)
            ->arg('$pool', service('cache.app'));
        $services
            ->set(CacheRuntime::class)
            ->arg('$cache', service(CacheItemPool::class))
            ->public();
    }

    /**
     * Fixes the default runtime reference to the container-managed runtime.
     *
     * The provider closure resolves through the container on every lookup, so
     * the registry never holds a runtime instance across kernel reboots.
     *
     * @throws LogicException when the bundle was booted without a container holding a runtime
     */
    #[Override]
    public function boot(): void
    {
        if (!isset($this->container)) {
            throw new LogicException('The Symfony container has not been installed on MagixCacheBundle.');
        }

        if (CacheRuntimeRegistry::isRegistered(CacheRuntimeRegistry::DEFAULT_NAME)) {
            return;
        }

        $container = $this->container;

        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            static function () use ($container): CacheRuntime {
                $runtime = $container->get(CacheRuntime::class);

                if (!$runtime instanceof CacheRuntime) {
                    throw new LogicException('The Symfony container returned an invalid MagixCache runtime.');
                }

                return $runtime;
            },
        );
    }
}
