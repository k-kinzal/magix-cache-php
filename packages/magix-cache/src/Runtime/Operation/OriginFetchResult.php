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
        $this->provenance = $value instanceof Cached
            ? OriginFetchProvenance::Origin
            : OriginFetchProvenance::Stale;
    }

    /**
     * Indicates whether this result came from the origin or retained stale data.
     */
    public OriginFetchProvenance $provenance;

    /**
     * Returns the origin value when the fetch reached the origin.
     *
     * @return Cached<covariant T>
     * @throws LogicException when this result retained stale data instead
     */
    public function originValue(): Cached
    {
        return $this->value instanceof Cached
            ? $this->value
            : throw new LogicException('A stale fetch result has no origin value.');
    }

    /**
     * Returns the retained entry when the fetch served stale data.
     *
     * @return CacheEntry<T>
     * @throws LogicException when this result came from the origin instead
     */
    public function staleEntry(): CacheEntry
    {
        return $this->value instanceof CacheEntry
            ? $this->value
            : throw new LogicException('An origin fetch result has no stale entry.');
    }
}
