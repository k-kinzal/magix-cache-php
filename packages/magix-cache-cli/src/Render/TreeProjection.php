<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Selects rows in the original hierarchy before promoting omitted callers.
 */
final readonly class TreeProjection
{
    /**
     * @param list<IgnorePattern> $patterns
     */
    public function __construct(private array $patterns, private UncachedMode $uncached)
    {
    }

    /**
     * Selects between rows using the unignored hierarchy, independently of display depth.
     *
     * @return array{list<CacheNode>, bool} Visible roots and whether this subtree declares Cache.
     */
    public function select(CacheNode $node, bool $declaredAncestor, int $remaining): array
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($node->boundary)) {
                return [[], false];
            }
        }

        $declared = $node->boundary->policy !== null;
        $descendant = false;
        $children = [];

        foreach ($node->children as $child) {
            [$visible, $hasDeclaration] = $this->select($child, $declaredAncestor || $declared, $remaining - 1);
            $descendant = $descendant || $hasDeclaration;
            $children = [...$children, ...$visible];
        }

        if ($remaining < 0) {
            return [[], $declared || $descendant];
        }

        if ($this->uncached->keeps($declared, $declaredAncestor, $descendant)) {
            return [[$node->withChildren($children)], $declared || $descendant];
        }

        return [array_map(fn (CacheNode $child): CacheNode => $child->withChildren($child->children, [...$node->via, $node->boundary->id(), ...$child->via]), $children), $descendant];
    }

}
