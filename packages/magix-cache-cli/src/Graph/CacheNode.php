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
     * @var list<string> Warnings from the original subtree, retained across display filtering.
     */
    public array $analysisWarnings;

    /**
     * Creates a cache tree node.
     *
     * @param list<CacheNode> $children
     * @param list<string> $notes Observations about how the tree was resolved.
     * @param list<CacheGap> $gaps Cache paths whose metadata propagation is not verified.
     * @param list<string>|null $analysisWarnings Null collects warnings from the original subtree; filters pass the existing collection.
     * @param list<CacheVariant>|null $metadataVariants Possible returned metadata, kept separate across exclusive paths.
     */
    public function __construct(
        public BoundaryDeclaration $boundary,
        public CacheEffect $effect,
        public array $children = [],
        public array $notes = [],
        public array $gaps = [],
        ?array $analysisWarnings = null,
        public ?array $metadataVariants = null,
    ) {
        $this->analysisWarnings = $analysisWarnings ?? array_values(array_unique([
            ...array_map(static fn (string $note): string => $boundary->shortId().': '.$note, $notes),
            ...array_map(static fn (CacheGap $gap): string => $gap->label(), $gaps),
            ...array_merge(...array_map(static fn (self $child): array => $child->analysisWarnings, $children)),
        ]));
    }
}
