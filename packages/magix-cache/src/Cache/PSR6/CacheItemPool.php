<?php

declare(strict_types=1);

namespace Magix\Cache\Cache\PSR6;

use function ceil;

use Closure;
use DateTimeImmutable;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cache\CacheEntry;
use Override;
use Psr\Cache\CacheException as Psr6CacheException;
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
     * @throws CacheBackendFailure when the PSR-6 pool rejects or fails the read
     */
    #[Override]
    public function get(string $key, Closure $typeWitness): ?CacheEntry
    {
        unset($typeWitness);

        try {
            $item = $this->pool->getItem($key);
        } catch (Psr6CacheException $failure) {
            throw new CacheBackendFailure('The PSR-6 pool failed to read "'.$key.'".', previous: $failure);
        }

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
     * @throws CacheBackendFailure when the PSR-6 pool rejects or fails the write
     */
    #[Override]
    public function set(string $key, CacheEntry $entry): void
    {
        $retainedUntil = (new DateTimeImmutable('@0'))->setTimestamp((int) ceil($entry->retainedUntil));

        try {
            $item = $this->pool->getItem($key);
            $item->set($entry);
            $item->expiresAt($retainedUntil);
            $this->pool->save($item);
        } catch (Psr6CacheException $failure) {
            throw new CacheBackendFailure('The PSR-6 pool failed to write "'.$key.'".', previous: $failure);
        }
    }
}
