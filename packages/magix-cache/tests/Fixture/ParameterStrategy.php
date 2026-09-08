<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StrategyDefinition;

/**
 * Counts factory evaluations and composes independently constructed children.
 */
final class ParameterStrategy extends CompositeCacheStrategy
{
    /**
     * Number of evaluated construction recipes.
     */
    public static int $factories = 0;

    /**
     * Builds a fixed lifetime and a fresh stateful child.
     */
    public static function create(int $ttl = 30, string $label = 'bound'): StrategyDefinition
    {
        ++self::$factories;

        return parent::compose(
            StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: $ttl, maximum: $ttl),
            StrategyDefinition::of(StatefulStrategy::class, label: $label),
        );
    }
}
