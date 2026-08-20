<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Loads one product with a short shared cache.
 */
final class ProductQuery
{
    use Cacheable;

    /**
     * Returns the product record.
     *
     * @return Cached<array{id: int}>
     */
    #[Cache(ttl: 20, tags: ['product'])]
    public function execute(int $productId): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(['id' => $productId]));
    }
}
