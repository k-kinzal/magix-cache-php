<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheAccess;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use RuntimeException;

/**
 * Reads and writes storage, bypassing only classified backend failures.
 *
 * The Cache port declares its failures as CacheBackendFailure, so a storage
 * fault always arrives in the RuntimeException family. Each catch takes only
 * that family and hands the failure to the classifier the boundary declared,
 * rethrowing everything the classifier does not accept; without a classifier
 * every failure propagates unchanged. A backend that reports outside the
 * declared family violates the port contract and propagates as the bug it is.
 * Entries written under a different storage format are diagnosed as misses,
 * never served as hits.
 *
 * @internal
 */
final readonly class GuardedCache
{
    /**
     * Creates a guarded view over the runtime's storage.
     */
    public function __construct(
        private Cache $cache,
        private ?CacheObserver $observer = null,
    ) {
    }

    /**
     * Performs the lookup stage: a fresh hit, a stale candidate, or a miss.
     *
     * The judgement happens at one instant and never refreshes the stored
     * expiration. An expired entry still inside its physical retention is
     * returned as the stale candidate for the origin stage.
     *
     * @template T
     * @param Closure(): T $typeWitness
     * @return array{CacheEntry<T>|null, CacheEntry<T>|null} Fresh entry and stale candidate.
     * @throws RuntimeException when the read fails and no classifier accepts the failure
     */
    public function lookup(string $key, ?BackendErrorClassifier $classifier, Closure $typeWitness, float $now): array
    {
        $entry = $this->read($key, $classifier, $typeWitness);
        $stale = null;

        if ($entry !== null && $entry->retainedUntil > $now) {
            if ($entry->expiresAt > $now) {
                $this->observer?->observe(CacheEvent::FreshHit, $key);

                return [$entry, null];
            }

            $stale = $entry;
        }

        $this->observer?->observe(CacheEvent::Miss, $key);

        return [null, $stale];
    }

    /**
     * Reads one entry, converting classified failures and corrupt entries to misses.
     *
     * @template T
     * @param Closure(): T $typeWitness
     * @return CacheEntry<T>|null
     * @throws RuntimeException when the read fails and no classifier accepts the failure
     */
    public function read(string $key, ?BackendErrorClassifier $classifier, Closure $typeWitness): ?CacheEntry
    {
        try {
            $entry = $this->cache->get($key, $typeWitness);
        } catch (RuntimeException $error) {
            if ($classifier?->isBackendFailure($error, CacheAccess::Read) !== true) {
                throw $error;
            }

            $this->observer?->observe(CacheEvent::BackendBypassed, $key);

            return null;
        }

        if ($entry !== null && $entry->formatVersion !== CacheEntry::FORMAT_VERSION) {
            $this->observer?->observe(CacheEvent::CorruptEntry, $key);

            return null;
        }

        return $entry;
    }

    /**
     * Writes one entry, skipping the write on a classified failure.
     *
     * @template T
     * @param CacheEntry<T> $entry
     * @throws RuntimeException when the write fails and no classifier accepts the failure
     */
    public function write(string $key, CacheEntry $entry, ?BackendErrorClassifier $classifier): void
    {
        try {
            $this->cache->set($key, $entry);
        } catch (RuntimeException $error) {
            if ($classifier?->isBackendFailure($error, CacheAccess::Write) !== true) {
                throw $error;
            }

            $this->observer?->observe(CacheEvent::BackendBypassed, $key);

            return;
        }

        $this->observer?->observe(CacheEvent::Stored, $key);
    }
}
