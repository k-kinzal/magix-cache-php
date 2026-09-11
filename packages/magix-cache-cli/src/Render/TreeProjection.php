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
     * Retains the analyzed method itself, which names what the report answers.
     *
     * An entry point that converts its result, such as a controller action that
     * renders a response, declares no cache of its own. Selecting it as a row
     * would drop the very method the command was asked about, so only --ignore
     * removes it.
     *
     * @return list<CacheNode>
     */
    public function root(CacheNode $node): array
    {
        if ($this->ignored($node)) {
            return [];
        }

        return [$node->withChildren($this->callees($node, $node->boundary->policy !== null)[0])];
    }

    /**
     * Selects between rows using the unignored hierarchy, independently of display depth.
     *
     * @return array{list<CacheNode>, bool} Visible roots and whether this subtree declares Cache.
     */
    public function select(CacheNode $node, bool $declaredAncestor): array
    {
        if ($this->ignored($node)) {
            return [[], false];
        }

        $declared = $node->boundary->policy !== null;
        [$children, $descendant] = $this->callees($node, $declaredAncestor || $declared);

        if ($this->uncached->keeps($declared, $declaredAncestor, $descendant)) {
            return [[$node->withChildren($children)], $declared || $descendant];
        }

        return [$this->promote($node, $children), $descendant];
    }

    /**
     * Projects the calls of one row and reports whether they reach a declaration.
     *
     * @return array{list<CacheNode>, bool}
     */
    public function callees(CacheNode $node, bool $declaredAncestor): array
    {
        $children = [];
        $descendant = false;

        foreach ($node->children as $child) {
            [$visible, $declares] = $this->select($child, $declaredAncestor);
            $descendant = $descendant || $declares;
            $children = [...$children, ...$visible];
        }

        return [$children, $descendant];
    }

    /**
     * Records an omitted caller on every connection it stood on.
     *
     * @param list<CacheNode> $children
     * @return list<CacheNode>
     */
    public function promote(CacheNode $node, array $children): array
    {
        return array_map(
            static fn (CacheNode $child): CacheNode => $child->withChildren($child->children, [...$node->via, $node->boundary->id(), ...$child->via]),
            $children,
        );
    }

    /**
     * Reports whether a display filter removes this row together with its subtree.
     */
    public function ignored(CacheNode $node): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($node->boundary)) {
                return true;
            }
        }

        return false;
    }
}
