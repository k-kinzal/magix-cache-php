<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Composes a product page from three cached queries.
 */
final class ProductPageQuery
{
    use Cacheable;

    /**
     * Creates the page query.
     */
    public function __construct(
        private readonly ProductQuery $products,
        private readonly InventoryQuery $inventory,
        private readonly ViewerQuery $viewer,
    ) {
    }

    /**
     * Returns the rendered page payload.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 120, tags: ['page'])]
    public function execute(
        int $productId,
        int $viewerId,
        #[CacheIgnore]
        string $trace = '',
    ): Cached {
        return $this->cached(function () use ($productId, $viewerId): Cached {
            $product = $this->products->execute($productId);
            $inventory = $this->inventory->execute($productId);
            $viewer = $this->viewer->execute($viewerId);

            return $product->combine3($inventory, $viewer)->map(
                static fn (array $product, int $stock, int $viewer): int => $product['id'] + $stock + $viewer,
            );
        });
    }
}
