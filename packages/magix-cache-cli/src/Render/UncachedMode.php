<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Selects which ordinary method rows remain visible after cache analysis.
 */
enum UncachedMode: string
{
    /** Shows only ordinary methods between cache boundaries. */
    case Between = 'between';

    /** Shows every resolved ordinary call, including wholly uncached branches. */
    case All = 'all';

    /** Omits ordinary rows and promotes their visible cached descendants. */
    case None = 'none';

    /**
     * Returns whether a descendant row is visible after its own children are filtered.
     *
     * @param bool $cachedAncestor Whether a cache boundary precedes this row on the original path.
     */
    public function keeps(CacheNode $node, bool $cachedAncestor): bool
    {
        return $node->boundary->isCacheBoundary || $this === self::All
            || ($this === self::Between && $cachedAncestor && $node->children !== []);
    }
}
