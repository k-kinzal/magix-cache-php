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
     * @param int $depth Display depth counts original calls, including omitted methods.
     */
    public function __construct(private array $patterns = [], private UncachedMode $uncached = UncachedMode::Between, private int $depth = 8)
    {
    }

    /**
     * Returns a forest: an undeclared selected root follows the same rule as descendants.
     *
     * @return list<CacheNode>
     */
    public function apply(CacheNode $node): array
    {
        return (new TreeProjection($this->patterns, $this->uncached))->select($node, false, $this->depth)[0];
    }

}
