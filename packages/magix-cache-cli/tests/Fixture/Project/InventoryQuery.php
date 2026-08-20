<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Loads stock levels with a longer shared cache.
 */
final class InventoryQuery
{
    use Cacheable;

    /**
     * Returns the stock level of one product.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 60, tags: ['inventory'])]
    public function execute(int $productId): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($productId));
    }
}
