<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;

/**
 * Holds one boundary of a cache tree together with its dependencies.
 */
final readonly class CacheNode
{
    /**
     * Creates a cache tree node.
     *
     * @param list<CacheNode> $children
     * @param list<string> $notes Observations about how the tree was resolved.
     * @param list<CacheGap> $gaps Cache paths whose metadata propagation is not verified.
     */
    public function __construct(
        public BoundaryDeclaration $boundary,
        public CacheEffect $effect,
        public array $children = [],
        public array $notes = [],
        public array $gaps = [],
    ) {
    }
}
