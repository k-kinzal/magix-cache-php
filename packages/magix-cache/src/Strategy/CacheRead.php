<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use InvalidArgumentException;

use function is_finite;

use Magix\Cache\Cached;

/**
 * Exposes stored data and its physical retention to a strategy's lookup.
 *
 * A read is a candidate, not a fresh hit. The runtime judges freshness after
 * the chain returns. It remains independent of the internal storage entry.
 *
 * @template-covariant T
 */
final readonly class CacheRead
{
    /**
     * Creates one retained storage candidate.
     *
     * @param Cached<T> $cached
     * @throws InvalidArgumentException when expiration or retention is invalid
     */
    public function __construct(public Cached $cached, public float $retainedUntil)
    {
        $expiresAt = $cached->metadata->expiresAt;

        if ($expiresAt === null || !is_finite($retainedUntil) || $retainedUntil < $expiresAt) {
            throw new InvalidArgumentException('A storage candidate requires an expiration and a finite retention no earlier than it.');
        }
    }

    /**
     * Judges freshness and physical retention at the supplied instant.
     */
    public function isFresh(float $now): bool
    {
        return $now < $this->retainedUntil && $this->cached->metadata->isStorable($now);
    }
}
