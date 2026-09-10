<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Display;

use Magix\Cache\Cached;
use Tests\Package\Cli\Fixture\Project\ProductQuery;

/**
 * Returns a cached value through a receiver the analyzer cannot resolve.
 */
final readonly class UnresolvedLookup
{
    /**
     * Creates a lookup over a query it hands out at runtime.
     */
    public function __construct(private ProductQuery $products)
    {
    }

    /**
     * @return Cached<array<string, int>>
     */
    public function get(int $id): Cached
    {
        return $this->pick()->execute($id);
    }

    /**
     * Hands out the query, hiding its type from a call-site receiver.
     */
    public function pick(): ProductQuery
    {
        return $this->products;
    }
}
