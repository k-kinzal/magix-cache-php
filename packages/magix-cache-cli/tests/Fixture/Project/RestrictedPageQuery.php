<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

/**
 * Restricts a dependency's metadata below an uncached entry point.
 */
final class RestrictedPageQuery
{
    use Cacheable;

    /**
     * Receives the shared inventory cache that the page restricts.
     */
    public function __construct(private readonly InventoryQuery $inventory)
    {
    }

    /**
     * @return Cached<int>
     */
    public function show(int $productId): Cached
    {
        return $this->execute($productId);
    }

    /**
     * @return Cached<int>
     */
    #[Cache(ttl: 10, visibility: Visibility::Private)]
    public function execute(int $productId): Cached
    {
        return $this->cached(fn (): Cached => $this->inventory->execute($productId));
    }
}
