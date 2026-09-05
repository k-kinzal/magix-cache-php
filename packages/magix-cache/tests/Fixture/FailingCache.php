<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cache\CacheEntry;
use Override;

/**
 * Fails every storage operation with a declared backend failure.
 */
final readonly class FailingCache implements Cache
{
    /**
     * @template T
     * @param Closure(): T $typeWitness
     * @return CacheEntry<T>|null
     * @throws CacheBackendFailure always
     */
    #[Override]
    public function get(string $key, Closure $typeWitness): ?CacheEntry
    {
        unset($typeWitness);

        throw new CacheBackendFailure('The backend cannot read "'.$key.'".');
    }

    /**
     * @template T
     * @param CacheEntry<T> $entry
     * @throws CacheBackendFailure always
     */
    #[Override]
    public function set(string $key, CacheEntry $entry): void
    {
        unset($entry);

        throw new CacheBackendFailure('The backend cannot write "'.$key.'".');
    }
}
