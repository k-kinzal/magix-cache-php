<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\CacheVisibility;
use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

/**
 * Exercises class-level strategy bindings and metadata supplied by parameters.
 */
#[Cache(ttl: 60, tags: ['fixed'], visibility: Visibility::Private)]
#[UseStrategy(ParameterizedStrategy::class)]
final class ParameterQuery
{
    use Cacheable;

    /**
     * @param list<string> $tags
     * @return Cached<string>
     */
    public function fetch(
        #[CacheTtl] int $ttl = 30,
        #[CacheTags] array $tags = [],
        #[CacheVisibility] Visibility $visibility = Visibility::Shared,
        #[StrategyArgument('minimum')] int $min = 10,
    ): Cached {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }

    /**
     * @return Cached<string>
     */
    #[Cache]
    #[UseStrategy(ParameterizedStrategy::class, enabled: false)]
    public function auto(#[CacheTtl] int $ttl = 30): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }

    /**
     * @return Cached<string>
     */
    #[Cache]
    public function strategy(#[StrategyArgument('minimum')] int $ttl = 30): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }
}
