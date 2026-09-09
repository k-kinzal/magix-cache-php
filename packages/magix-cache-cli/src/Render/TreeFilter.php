<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Selects visible rows and prunes ignored subtrees without changing analysis results.
 */
final readonly class TreeFilter
{
    /**
     * Creates an independent display filter shared by every output format.
     *
     * @param list<IgnorePattern> $patterns
     * @param UncachedMode $uncached Ordinary rows to retain; omitted rows promote their children, while ignore matches prune the entire subtree.
     */
    public function __construct(
        private array $patterns = [],
        private UncachedMode $uncached = UncachedMode::Between,
    ) {
    }

    /**
     * Returns the visible tree, retaining the selected root unless it is ignored.
     *
     * @param bool $cachedAncestor Whether a cache boundary precedes this node on the original path.
     */
    public function apply(CacheNode $node, bool $cachedAncestor = false): ?CacheNode
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($node->boundary)) {
                return null;
            }
        }

        $children = [];
        $cachedAncestor = $cachedAncestor || $node->boundary->isCacheBoundary;

        foreach ($node->children as $child) {
            $visible = $this->apply($child, $cachedAncestor);

            if ($visible === null) {
                continue;
            }

            if ($this->uncached->keeps($visible, $cachedAncestor)) {
                $children[] = $visible;
            } else {
                $children = [...$children, ...$visible->children];
            }
        }

        return new CacheNode($node->boundary, $node->effect, $children, $node->notes, $node->gaps, $node->analysisWarnings);
    }
}
