<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Expiration;

use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StrategyDefinition;

/**
 * Composes clock candidates in different timezones with a duration constraint.
 */
final class MultipleExpirationComposition extends CompositeCacheStrategy
{
    /**
     * Composes nested definitions and their independent expiration contracts.
     */
    public static function create(): StrategyDefinition
    {
        return parent::compose(
            MultipleExpirationStrategy::create(timezone: 'Asia/Tokyo'),
            StrategyDefinition::of(KeySpreadExpirationStrategy::class, 30, 60),
        );
    }
}
