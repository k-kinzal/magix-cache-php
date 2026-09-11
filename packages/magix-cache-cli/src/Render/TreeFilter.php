<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Projects the analyzed graph by Cache declarations without modifying its facts.
 */
final readonly class TreeFilter
{
    /**
     * @param list<IgnorePattern> $patterns Matching subtrees are removed before promotion.
     * @param int $depth Display depth counts printed rows, so a compact mode never reaches less far than an expanded one.
     */
    public function __construct(private array $patterns = [], private UncachedMode $uncached = UncachedMode::Between, private int $depth = 8)
    {
    }

    /**
     * Returns the analyzed root, retained by every mode, with its selected descendants.
     *
     * @return list<CacheNode>
     */
    public function apply(CacheNode $node): array
    {
        return $this->truncate((new TreeProjection($this->patterns, $this->uncached))->root($node), $this->depth);
    }

    /**
     * Limits how far selected rows are printed, after selection read the whole hierarchy.
     *
     * @param list<CacheNode> $nodes
     * @return list<CacheNode>
     */
    public function truncate(array $nodes, int $remaining): array
    {
        if ($remaining < 0) {
            return [];
        }

        return array_map(fn (CacheNode $node): CacheNode => $node->withChildren($this->truncate($node->children, $remaining - 1)), $nodes);
    }
}
