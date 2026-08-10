<?php

declare(strict_types=1);

namespace Magix\Cache\Cache\PSR16;

use function ceil;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Clock\SystemClock;
use Override;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Adapts a PSR-16 simple cache to the Magix cache contract.
 */
final readonly class SimpleCache implements Cache
{
    /**
     * Creates a Magix cache backed by the supplied PSR-16 cache.
     */
    public function __construct(
        private CacheInterface $cache,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * @template T
     * @param Closure(): T $typeWitness
     * @return CacheEntry<T>|null
     */
    #[Override]
    public function get(string $key, Closure $typeWitness): ?CacheEntry
    {
        unset($typeWitness);

        $value = $this->cache->get($key);

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
        $now = (float) $this->clock->now()->format('U.u');
        $ttl = (int) ceil($entry->retainedUntil - $now);

        $this->cache->set($key, $entry, $ttl);
    }
}
