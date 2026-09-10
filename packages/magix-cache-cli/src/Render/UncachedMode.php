<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

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
     * Returns whether a row is visible based solely on declarations in the hierarchy.
     *
     * @param bool $cachedAncestor Whether a cache boundary precedes this row on the original path.
     */
    public function keeps(bool $declared, bool $cachedAncestor, bool $declaredDescendant): bool
    {
        return $declared || $this === self::All
            || ($this === self::Between && $cachedAncestor && $declaredDescendant);
    }
}
