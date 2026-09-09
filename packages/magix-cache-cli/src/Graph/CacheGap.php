<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;

/**
 * Identifies a cache-to-cache path whose ordinary methods may detach metadata.
 */
final readonly class CacheGap
{
    /**
     * @param list<BoundaryDeclaration> $path The cache parent, ordinary intermediates, and first cache child.
     */
    public function __construct(public array $path)
    {
    }

    /**
     * Returns a diagnostic without claiming that metadata is definitely lost.
     */
    public function label(): string
    {
        return 'cache propagation unanalyzed: '.implode(' -> ', array_map(static fn (BoundaryDeclaration $boundary): string => $boundary->shortId(), $this->path));
    }

    /**
     * Finds paths through ordinary methods, stopping at the first cache boundary.
     *
     * @param list<BoundaryDeclaration> $path
     * @return list<self>
     */
    public static function through(CacheNode $node, array $path): array
    {
        $path[] = $node->boundary;

        if ($node->boundary->isCacheBoundary) {
            return [new self($path)];
        }

        $gaps = [];

        foreach ($node->children as $child) {
            $gaps = [...$gaps, ...self::through($child, $path)];
        }

        return $gaps;
    }
}
