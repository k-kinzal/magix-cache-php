<?php

declare(strict_types=1);

namespace Bench\AllMiss;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use Override;

/**
 * Real array storage, with neither PSR adapter nor external backend overhead.
 */
final class MemoryCache implements Cache
{
    /** @var array<string, CacheEntry<mixed>> */
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

        /** @var CacheEntry<T>|null $entry */
        $entry = $this->entries[$key] ?? null;

        return $entry;
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

    /**
     * Reports retained entries for correctness checks outside timing.
     */
    public function count(): int
    {
        return count($this->entries);
    }
}
