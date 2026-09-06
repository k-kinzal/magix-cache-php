<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use Throwable;

/**
 * Composes the product cache behavior from reusable strategies.
 */
final class ProductCacheStrategy extends CompositeCacheStrategy
{
    /**
     * Builds the composed product cache strategy.
     */
    public static function create(int $min = 30): CacheStrategy
    {
        return parent::compose(
            new KeySpreadExpirationStrategy(
                minimum: $min,
                maximum: 60,
            ),
            new ProductFreshnessStrategy(
                minimum: $min,
            ),
            new StaleIfErrorCacheStrategy(
                maxAge: 300,
                accepts: static fn (Throwable $error): bool => $error instanceof UpstreamUnavailable,
            ),
        );
    }
}
