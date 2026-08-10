<?php

declare(strict_types=1);

namespace Magix\Cache\Symfony;

use LogicException;
use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
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
     * Installs the container-managed runtime for Cacheable.
     */
    #[Override]
    public function boot(): void
    {
        if (!isset($this->container)) {
            throw new LogicException('The Symfony container has not been installed on MagixCacheBundle.');
        }

        $runtime = $this->container->get(CacheRuntime::class);

        if (!$runtime instanceof CacheRuntime) {
            throw new LogicException('The Symfony container returned an invalid MagixCache runtime.');
        }

        CacheRuntime::setCurrent($runtime);
    }

    /**
     * Removes the process-local runtime during kernel shutdown.
     */
    #[Override]
    public function shutdown(): void
    {
        CacheRuntime::setCurrent(null);
    }
}
