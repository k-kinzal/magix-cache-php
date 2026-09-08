<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

/**
 * Collects independently cached data for a view without caching the action itself.
 */
final readonly class ProductController
{
    /**
     * Creates an action backed by three cached queries.
     */
    public function __construct(
        private ProductQuery $products,
        private InventoryQuery $inventory,
        private ViewerQuery $viewer,
    ) {
    }

    /**
     * @return array{product: array{id: int}, stock: int, viewer: int}
     */
    public function show(int $productId, int $viewerId): array
    {
        $product = $this->products->execute($productId);
        $stock = $this->inventory->execute($productId);
        $viewer = $this->viewer->execute($viewerId);

        return ['product' => $product->value(), 'stock' => $stock->value(), 'viewer' => $viewer->value()];
    }

    /**
     * @return array{product: array{id: int}, stock: int, viewer: int}
     */
    public function index(int $productId, int $viewerId): array
    {
        return $this->show($productId, $viewerId);
    }

    /**
     * Returns data from queries injected into the action parameters.
     *
     * @return array{product: array{id: int}, stock: int}
     */
    public function injected(ProductQuery $products, InventoryQuery $inventory, int $productId): array
    {
        return ['product' => $products->execute($productId)->value(), 'stock' => $inventory->execute($productId)->value()];
    }
}
