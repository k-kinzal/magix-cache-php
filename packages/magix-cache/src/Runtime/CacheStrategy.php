<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;

/**
 * Intercepts cache reads, origin fetches, and cache writes.
 */
interface CacheStrategy
{
    /**
     * @template T
     * @param Closure(CacheGet): (CacheEntry<T>|null) $next
     * @return CacheEntry<T>|null
     */
    public function get(CacheGet $operation, Closure $next): ?CacheEntry;

    /**
     * @template T
     * @param OriginFetch<T> $operation
     * @param Closure(OriginFetch<T>): OriginFetchResult<T> $next
     * @return OriginFetchResult<T>
     */
    public function fetch(OriginFetch $operation, Closure $next): OriginFetchResult;

    /**
     * @template T
     * @param CacheSet<T> $operation
     * @param Closure(CacheSet<T>): void $next
     */
    public function set(CacheSet $operation, Closure $next): void;
}
