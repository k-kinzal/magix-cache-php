<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture;

use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StrategyDefinition;

/**
 * Exposes a factory side effect so static analysis can prove it never runs it.
 */
final class ParameterizedStrategy extends CompositeCacheStrategy
{
    /**
     * Number of actual factory calls.
     */
    public static int $calls = 0;

    /**
     * Creates a bounded strategy from one invocation argument.
     */
    public static function create(int $minimum = 30): StrategyDefinition
    {
        ++self::$calls;

        return StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: $minimum, maximum: 60);
    }
}
