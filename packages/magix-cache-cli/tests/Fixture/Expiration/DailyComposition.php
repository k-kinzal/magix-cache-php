<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Expiration;

use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StrategyDefinition;

/**
 * Composes clock candidates in different timezones with a duration constraint.
 */
final class DailyComposition extends CompositeCacheStrategy
{
    /**
     * Composes nested definitions and their independent expiration contracts.
     */
    public static function create(): StrategyDefinition
    {
        return parent::compose(
            DailyExpirationStrategy::create('12:00', '12:15', 'Asia/Tokyo'),
            DailyExpirationStrategy::create('09:00', null, 'America/New_York'),
            StrategyDefinition::of(KeySpreadExpirationStrategy::class, 30, 60),
        );
    }
}
