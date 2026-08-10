<?php

declare(strict_types=1);

namespace Magix\Cache\Cache;

use Closure;

/**
 * Defines storage operations for complete Magix cache entries.
 */
interface Cache
{
    /**
     * Returns one complete internal cache entry.
     *
     * @template T
     * @param Closure(): T $typeWitness
     * @return CacheEntry<T>|null
     */
    public function get(string $key, Closure $typeWitness): ?CacheEntry;

    /**
     * Persists one complete internal cache entry.
     *
     * @template T
     * @param CacheEntry<T> $entry
     */
    public function set(string $key, CacheEntry $entry): void;
}
