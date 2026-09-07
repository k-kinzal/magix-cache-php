<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

/**
 * Names one observable outcome of a runtime execution stage.
 */
enum CacheEvent
{
    /**
     * A stored entry answered the lookup while still fresh.
     */
    case FreshHit;

    /**
     * No fresh entry answered the lookup.
     */
    case Miss;

    /**
     * A retained expired entry stood in for a failed origin.
     */
    case StaleServed;

    /**
     * The computed result was persisted.
     */
    case Stored;

    /**
     * The computed result was returned without being persisted.
     */
    case StoreSkipped;

    /**
     * A stored entry could not be trusted and was treated as a miss.
     */
    case CorruptEntry;

    /**
     * A classified backend failure was bypassed instead of propagated.
     */
    case BackendBypassed;
    /**
     * Resolves a diagnostic name supplied by a strategy answer.
     */
    public static function named(?string $name): ?self
    {
        foreach (self::cases() as $event) {
            if ($event->name === $name) {
                return $event;
            }
        }

        return null;
    }
}
