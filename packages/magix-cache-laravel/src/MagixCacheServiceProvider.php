<?php

declare(strict_types=1);

namespace Magix\Cache\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Magix\Cache\Cache\PSR16\SimpleCache;
use Magix\Cache\CacheRuntime;
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
     * Installs the container-managed runtime for Cacheable.
     */
    public function boot(CacheRuntime $runtime): void
    {
        CacheRuntime::setCurrent($runtime);
    }
}
