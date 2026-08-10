<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use Override;

/**
 * Stores Magix cache entries in memory for runtime unit tests.
 */
final class MemoryCache implements Cache
{
    /** @var array<string, object> */
    private array $entries = [];

    /**
     * @template T
     * @param Closure(): T $typeWitness
     * @return CacheEntry<T>|null
     */
    #[Override]
    public function get(string $key, Closure $typeWitness): ?CacheEntry
    {
        unset($typeWitness);

        $value = $this->entries[$key] ?? null;

        if (!$value instanceof CacheEntry) {
            return null;
        }

        /** @var CacheEntry<T> $value */
        return $value;
    }

    /**
     * @template T
     * @param CacheEntry<T> $entry
     */
    #[Override]
    public function set(string $key, CacheEntry $entry): void
    {
        $this->entries[$key] = $entry;
    }
}
