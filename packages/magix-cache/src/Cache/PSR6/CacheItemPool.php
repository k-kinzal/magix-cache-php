<?php

declare(strict_types=1);

namespace Magix\Cache\Cache\PSR6;

use function ceil;

use Closure;
use DateTimeImmutable;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use Override;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Adapts a PSR-6 cache item pool to the Magix cache contract.
 */
final readonly class CacheItemPool implements Cache
{
    /**
     * Creates a Magix cache backed by the supplied PSR-6 pool.
     */
    public function __construct(private CacheItemPoolInterface $pool)
    {
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

        $item = $this->pool->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

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
        $item = $this->pool->getItem($key);
        $item->set($entry);
        $item->expiresAt(new DateTimeImmutable('@'.(string) (int) ceil($entry->retainedUntil)));
        $this->pool->save($item);
    }
}
