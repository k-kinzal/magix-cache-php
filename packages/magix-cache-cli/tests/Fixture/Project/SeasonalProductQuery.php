<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Combines a strategy composition with an already constrained dependency.
 */
final class SeasonalProductQuery
{
    use Cacheable;

    /**
     * Creates the seasonal query.
     */
    public function __construct(private readonly ProductQuery $products)
    {
    }

    /**
     * Returns the seasonal product payload.
     *
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(strategy: ProductCacheStrategy::class, min: 30)]
    public function execute(int $productId): Cached
    {
        return $this->cached(function () use ($productId): Cached {
            $product = $this->products->execute($productId);

            return $product->map(static fn (array $data): int => $data['id']);
        });
    }

    /**
     * Returns the assumed variant built from an external strategy.
     *
     * @return Cached<int>
     */
    #[Cache]
    #[UseStrategy(strategy: AssumedCacheStrategy::class, min: 45)]
    public function assumed(int $productId): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($productId));
    }
}
