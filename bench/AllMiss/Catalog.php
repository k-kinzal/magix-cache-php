<?php

declare(strict_types=1);

namespace Bench\AllMiss;

/**
 * Deterministic in-process origin work shared by both query implementations.
 */
final readonly class Catalog
{
    /** @var list<int> */
    private array $prices;

    /**
     * Prepares 32 catalog prices outside the timed operation.
     */
    public function __construct()
    {
        $this->prices = range(100, 3200, 100);
    }

    /**
     * Totals the fixed catalog using a five-ID quantity cycle.
     */
    public function total(int $id): int
    {
        $total = 0;
        foreach ($this->prices as $index => $price) {
            $total += $price * (1 + ($id + $index) % 5);
        }

        return $total;
    }
}
