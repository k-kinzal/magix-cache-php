<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cache\CacheEntry;
use Override;

/**
 * Records writes so a test can replay any subset into a fresh backend.
 */
final class RecordingCache implements Cache
{
    /**
     * Entries written since the recording was last cleared.
     *
     * @var array<string, CacheEntry<mixed>>
     */
    public array $entries = [];

    /**
     * Decorates a real storage adapter without changing its payloads.
     */
    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * @template T
     * @param Closure(): T $typeWitness
     * @return CacheEntry<T>|null
     * @throws CacheBackendFailure when the backing cache fails the read
     */
    #[Override]
    public function get(string $key, Closure $typeWitness): ?CacheEntry
    {
        return $this->cache->get($key, $typeWitness);
    }

    /**
     * @template T
     * @param CacheEntry<T> $entry
     * @throws CacheBackendFailure when the backing cache fails the write
     */
    #[Override]
    public function set(string $key, CacheEntry $entry): void
    {
        $this->cache->set($key, $entry);
        $this->entries[$key] = $entry;
    }
}
