<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Declares strategy compositions the way a boundary uses them.
 */
#[Cache(ttl: 60)]
#[UseStrategy(strategy: ProductCacheStrategy::class, min: 30)]
final class StrategyQuery
{
    use Cacheable;

    /**
     * Uses the class-level strategy declaration.
     *
     * @return Cached<non-falsy-string>
     */
    public function viaClass(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('class:'.$id));
    }

    /**
     * Replaces the class-level strategy with different arguments.
     *
     * @return Cached<non-falsy-string>
     */
    #[UseStrategy(strategy: ProductCacheStrategy::class, min: 60)]
    public function viaMethod(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('method:'.$id));
    }

    /**
     * Disables the class-level strategy declaration.
     *
     * @return Cached<non-falsy-string>
     */
    #[UseStrategy(strategy: ProductCacheStrategy::class, enabled: false)]
    public function withoutStrategy(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of('plain:'.$id));
    }
}
