<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Operation;

use LogicException;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;

/**
 * Carries the typed result of an origin-fetch strategy chain.
 *
 * @template-covariant T
 */
final readonly class OriginFetchResult
{
    /**
     * Creates a result from either an origin value or a retained cache entry.
     *
     * @param Cached<covariant T>|CacheEntry<T> $value
     */
    public function __construct(
        private Cached|CacheEntry $value,
    ) {
        $this->outcome = $value instanceof Cached
            ? OriginFetchOutcome::Origin
            : OriginFetchOutcome::Stale;
    }

    /**
     * Indicates whether this result came from the origin or retained stale data.
     */
    public OriginFetchOutcome $outcome;

    /**
     * Returns the origin value for an origin outcome.
     *
     * @return Cached<covariant T>
     */
    public function originValue(): Cached
    {
        return $this->value instanceof Cached
            ? $this->value
            : throw new LogicException('A stale fetch result has no origin value.');
    }

    /**
     * Returns the retained entry for a stale outcome.
     *
     * @return CacheEntry<T>
     */
    public function staleEntry(): CacheEntry
    {
        return $this->value instanceof CacheEntry
            ? $this->value
            : throw new LogicException('An origin fetch result has no stale entry.');
    }
}
