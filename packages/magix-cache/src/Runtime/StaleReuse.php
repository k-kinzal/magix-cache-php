<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Metadata\CacheMetadata;
use Throwable;

/**
 * Judges when a retained expired entry may stand in for a failed origin.
 *
 * The judgement is pure: it can accept or reject the candidate the lookup
 * retained, but never replace its expiration or value. Serving a candidate
 * keeps its expired expiration, so being servable and being storable stay
 * separate judgements.
 *
 * @internal
 */
final readonly class StaleReuse
{
    /**
     * Returns the candidate when the failure and the candidate are eligible.
     *
     * At judgement time s the candidate must satisfy expiresAt <= s,
     * s < retainedUntil, and s < expiresAt + maxAge; a candidate exactly at
     * its retention or age limit is rejected.
     *
     * @template T
     * @param CacheEntry<T>|null $stale
     * @return CacheEntry<T>|null
     */
    public function candidate(?StaleIfError $behavior, ?CacheEntry $stale, Throwable $error, float $now): ?CacheEntry
    {
        if ($behavior === null || $stale === null || !$behavior->captures($error)) {
            return null;
        }

        if ($stale->expiresAt <= $now && $now < $stale->retainedUntil && $now < $stale->expiresAt + $behavior->maxAge) {
            return $stale;
        }

        return null;
    }

    /**
     * Returns the physical retention deadline stale handling asks for.
     *
     * Extending retention never changes the expiration itself.
     */
    public function retention(?StaleIfError $behavior, CacheMetadata $metadata): ?float
    {
        if ($behavior === null || $metadata->expiresAt === null) {
            return null;
        }

        return $metadata->expiresAt + $behavior->maxAge;
    }
}
