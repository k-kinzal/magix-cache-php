<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Operation;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;

/**
 * Terminates strategy chains at the configured cache or origin computation.
 *
 * @template T
 * @internal
 */
final readonly class CacheOperationTerminal
{
    /** @var Closure(): T */
    private Closure $typeWitness;

    /**
     * @param Closure(): T $typeWitness
     */
    public function __construct(
        private Cache $cache,
        Closure $typeWitness,
    ) {
        $this->typeWitness = $typeWitness;
    }

    /**
     * Reads from the terminal cache implementation.
     *
     * @return CacheEntry<T>|null
     */
    public function get(CacheGet $operation): ?CacheEntry
    {
        return $this->cache->get($operation->key, $this->typeWitness);
    }

    /**
     * Invokes the terminal origin computation.
     *
     * @param OriginFetch<T> $operation
     * @return OriginFetchResult<T>
     */
    public function fetch(OriginFetch $operation): OriginFetchResult
    {
        return new OriginFetchResult($operation->invoke());
    }

    /**
     * Writes to the terminal cache implementation.
     *
     * @param CacheSet<T> $operation
     */
    public function set(CacheSet $operation): void
    {
        $this->cache->set($operation->key, $operation->entry());
    }
}
