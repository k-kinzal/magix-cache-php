<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Metadata\CacheMetadata;

/**
 * Converts values between the public and internal cache representations.
 *
 * @internal
 */
final readonly class CacheEntryConverter
{
    /**
     * @template T
     * @param CacheEntry<T> $entry
     * @return Cached<T>
     */
    public function toCached(CacheEntry $entry): Cached
    {
        return Cached::of($entry->value(), new CacheMetadata(
            expiresAt: $entry->expiresAt,
            tags: $entry->tags,
            visibility: $entry->visibility,
            reasons: $entry->reasons,
        ));
    }

    /**
     * @template T
     * @param Cached<T> $result
     * @return CacheEntry<T>|null
     */
    public function toEntry(Cached $result, float $now): ?CacheEntry
    {
        $expiresAt = $result->metadata->expiresAt;

        if ($expiresAt === null || !$result->metadata->isStorable($now)) {
            return null;
        }

        return new CacheEntry(
            value: $result->value(),
            expiresAt: $expiresAt,
            tags: $result->metadata->tags,
            visibility: $result->metadata->visibility,
            reasons: $result->metadata->reasons,
        );
    }
}
