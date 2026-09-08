<?php

declare(strict_types=1);

namespace Bench\AllMiss;

/**
 * Computes the same read-model tree without any Magix Cache types or calls.
 */
final readonly class PlainQuery
{
    /**
     * Supplies the same catalog used by the cached query.
     */
    public function __construct(private Catalog $catalog)
    {
    }

    /**
     * Evaluates every leaf and adds child totals up to the root.
     */
    public function execute(int $id, int $depth): int
    {
        if ($depth === 0) {
            return $this->catalog->total($id);
        }

        return $this->execute($id * 2, $depth - 1)
            + $this->execute($id * 2 + 1, $depth - 1);
    }
}
