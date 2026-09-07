<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheKey;
use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\CacheVisibility;
use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

/**
 * Boundaries whose configuration is supplied by their callers.
 */
#[Cache(ttl: 60, tags: ['static'])]
final class ParameterQuery
{
    use Cacheable;

    /**
     * Number of successful origin executions.
     */
    public int $calls = 0;

    /**
     * @param list<string> $tags
     * @return Cached<int>
     */
    public function fetch(
        #[CacheTtl] int $ttl = 30,
        #[CacheTags] array $tags = [],
        #[CacheVisibility] Visibility $visibility = Visibility::Shared,
    ): Cached {
        return $this->cached(fn (): Cached => Cached::of(++$this->calls));
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    public function auto(#[CacheTtl] int $ttl): Cached
    {
        return $this->cached(fn (): Cached => Cached::of(++$this->calls));
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(ParameterStrategy::class, label: 'both')]
    public function both(#[CacheTtl, StrategyArgument('ttl')] int $ttl): Cached
    {
        return $this->cached(fn (): Cached => Cached::of(++$this->calls));
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(ParameterStrategy::class)]
    public function strategy(
        #[StrategyArgument('ttl')]
        #[CacheKey(reduce: [self::class, 'same'])]
        int $ttl = 30,
    ): Cached {
        return $this->cached(fn (): Cached => Cached::of(++$this->calls));
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(ParameterStrategy::class)]
    public function nested(#[StrategyArgument('ttl')] int $ttl, bool $inner = false): Cached
    {
        return $this->cached(fn (): Cached => $inner ? Cached::of(++$this->calls) : $this->nested(10, true));
    }

    /**
     * @param Cached<string> $dependency
     * @return Cached<string>
     */
    public function composed(Cached $dependency, #[CacheTtl] int $ttl, #[CacheTtl] int $otherTtl = 60): Cached
    {
        return $this->cached(static fn (): Cached => $dependency);
    }

    /**
     * Intentionally reduces all values to the same key input.
     */
    public static function same(int $value): string
    {
        unset($value);

        return 'same';
    }
}
