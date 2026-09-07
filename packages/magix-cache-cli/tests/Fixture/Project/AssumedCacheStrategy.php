<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\AssumeTtl;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;

/**
 * Skips the analysis of one child with an explicit assumption.
 */
final class AssumedCacheStrategy extends CompositeCacheStrategy
{
    /**
     * Builds the composition with an assumed external strategy.
     */
    #[AssumeTtl(strategy: ExternalTtlStrategy::class, min: new Arg('min'), max: 300)]
    public static function create(int $min = 30): CacheStrategy
    {
        return parent::compose(
            new ExternalTtlStrategy(),
            new KeySpreadExpirationStrategy(minimum: $min, maximum: 60),
        );
    }
}
