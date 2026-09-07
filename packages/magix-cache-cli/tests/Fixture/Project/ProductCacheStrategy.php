<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use Magix\Cache\Strategy\StrategyDefinition;

/**
 * Composes the product cache behavior from reusable strategies.
 */
final class ProductCacheStrategy extends CompositeCacheStrategy
{
    /**
     * Builds the composed product cache strategy.
     */
    public static function create(int $min = 30): StrategyDefinition
    {
        return parent::compose(
            StrategyDefinition::of(
                KeySpreadExpirationStrategy::class,
                minimum: $min,
                maximum: 60,
            ),
            StrategyDefinition::of(
                ProductFreshnessStrategy::class,
                minimum: $min,
            ),
            StrategyDefinition::of(
                StaleIfErrorCacheStrategy::class,
                maxAge: 300,
                exceptions: [UpstreamUnavailable::class],
            ),
        );
    }
}
