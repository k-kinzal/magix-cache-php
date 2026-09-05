<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

/**
 * Distinguishes which side of the storage boundary a failure arrived from.
 */
enum CacheAccess
{
    /**
     * The failure happened while reading a stored entry.
     */
    case Read;

    /**
     * The failure happened while persisting an entry.
     */
    case Write;
}
