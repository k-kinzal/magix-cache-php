<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Caches a promoted product through a declared strategy composition.
 */
final class PromotedProductQuery
{
    use Cacheable;

    /**
     * Returns the promoted product payload.
     *
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(strategy: ProductCacheStrategy::class, min: 60)]
    public function execute(int $productId): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($productId));
    }

    /**
     * Returns the seasonal variant with the spread left open.
     *
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(strategy: ProductCacheStrategy::class, min: 30)]
    public function seasonal(int $productId): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($productId));
    }
}
