<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;

/**
 * Converts values between the public and internal cache representations.
 *
 * @internal
 */
final readonly class CacheEntryConverter
{
    /**
     * Returns the stored value with the exact metadata it was stored with.
     *
     * A retained expired entry keeps its expired expiration, so a parent that
     * only composes it inherits the expired deadline. An explicit parent TTL
     * override may choose a new deadline before conversion.
     *
     * @template T
     * @param CacheEntry<T> $entry
     * @return Cached<T>
     */
    public function toCached(CacheEntry $entry): Cached
    {
        return Cached::of($entry->value(), $entry->metadata);
    }

    /**
     * Returns a storable entry, or null when the result may not be stored now.
     *
     * @template T
     * @param Cached<T> $result
     * @param float|null $retainedUntil Physical retention deadline for stale handling.
     * @return CacheEntry<T>|null
     */
    public function toEntry(Cached $result, float $now, ?float $retainedUntil = null): ?CacheEntry
    {
        if (!$result->metadata->isStorable($now)) {
            return null;
        }

        return new CacheEntry(
            value: $result->value(),
            metadata: $result->metadata,
            retainedUntil: $retainedUntil,
        );
    }
}
