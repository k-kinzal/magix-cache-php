<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Display;

use Tests\Package\Cli\Fixture\Project\ProductQuery;

/**
 * Returns a plain value, detaching the called query's metadata.
 */
final readonly class InventoryLookup
{
    /**
     * Creates a lookup backed by a cached product query.
     */
    public function __construct(private ProductQuery $products)
    {
    }

    /**
     * Returns the product identifier without its metadata.
     */
    public function get(int $id): int
    {
        return $this->products->execute($id)->value()['id'];
    }
}
