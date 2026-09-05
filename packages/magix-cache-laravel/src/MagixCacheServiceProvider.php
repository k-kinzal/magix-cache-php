<?php

declare(strict_types=1);

namespace Magix\Cache\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Magix\Cache\Cache\PSR16\SimpleCache;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;

/**
 * Registers MagixCache against Laravel's default cache repository.
 */
final class MagixCacheServiceProvider extends ServiceProvider
{
    /**
     * Registers the runtime against Laravel's PSR-16 repository.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(CacheRuntime::class, static function (Container $app): CacheRuntime {
            $repository = $app->make('cache.store');

            if (!$repository instanceof Repository) {
                throw new LogicException('Laravel cache.store must implement the cache Repository contract.');
            }

            return new CacheRuntime(new SimpleCache($repository));
        });
    }

    /**
     * Fixes the default runtime reference to the container-managed runtime.
     *
     * The provider closure resolves through the container on every lookup, so
     * the registry never holds a runtime instance across requests.
     */
    public function boot(): void
    {
        if (CacheRuntimeRegistry::isRegistered(CacheRuntimeRegistry::DEFAULT_NAME)) {
            return;
        }

        $app = $this->app;

        CacheRuntimeRegistry::register(
            CacheRuntimeRegistry::DEFAULT_NAME,
            static fn (): CacheRuntime => $app->make(CacheRuntime::class),
        );
    }
}
