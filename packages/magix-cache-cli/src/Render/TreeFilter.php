<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Prunes ignored subtrees after analysis, preserving each remaining node's effects.
 */
final readonly class TreeFilter
{
    /**
     * Creates an independent display filter shared by every output format.
     *
     * @param list<IgnorePattern> $patterns
     */
    public function __construct(private array $patterns = [])
    {
    }

    /**
     * Returns the visible tree, or null when its root is ignored.
     */
    public function apply(CacheNode $node): ?CacheNode
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($node->boundary)) {
                return null;
            }
        }

        $children = [];

        foreach ($node->children as $child) {
            $visible = $this->apply($child);

            if ($visible !== null) {
                $children[] = $visible;
            }
        }

        return new CacheNode($node->boundary, $node->effect, $children, $node->notes);
    }
}
