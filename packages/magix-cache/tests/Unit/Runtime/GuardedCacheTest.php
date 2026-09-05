<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\DefaultBackendErrorClassifier;
use Magix\Cache\Runtime\GuardedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\FailingCache;
use Tests\Fixture\ForeignFormatEntry;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\RecordingObserver;

#[CoversClass(GuardedCache::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(DefaultBackendErrorClassifier::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
final class GuardedCacheTest extends TestCase
{
    public function testLookupReturnsAFreshEntryBeforeExpiration(): void
    {
        $store = new MemoryCache();
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0));
        $store->set('key', $entry);
        $observer = new RecordingObserver();

        [$fresh, $stale] = (new GuardedCache($store, $observer))
            ->lookup('key', null, static fn (): string => '', 110.0);

        self::assertSame($entry, $fresh);
        self::assertNull($stale);
        self::assertSame([CacheEvent::FreshHit], $observer->events);
    }

    public function testLookupRetainsAnExpiredEntryAsTheStaleCandidate(): void
    {
        $store = new MemoryCache();
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0), retainedUntil: 150.0);
        $store->set('key', $entry);
        $observer = new RecordingObserver();

        [$fresh, $stale] = (new GuardedCache($store, $observer))
            ->lookup('key', null, static fn (): string => '', 130.0);

        self::assertNull($fresh);
        self::assertSame($entry, $stale);
        self::assertSame([CacheEvent::Miss], $observer->events);
    }

    public function testLookupReportsAMissBeyondPhysicalRetention(): void
    {
        $store = new MemoryCache();
        $store->set('key', new CacheEntry('value', new CacheMetadata(expiresAt: 120.0), retainedUntil: 150.0));
        $observer = new RecordingObserver();

        [$fresh, $stale] = (new GuardedCache($store, $observer))
            ->lookup('key', null, static fn (): string => '', 150.0);

        self::assertNull($fresh);
        self::assertNull($stale);
        self::assertSame([CacheEvent::Miss], $observer->events);
    }

    public function testReadReturnsTheStoredEntry(): void
    {
        $store = new MemoryCache();
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0));
        $store->set('key', $entry);

        self::assertSame($entry, (new GuardedCache($store))->read('key', null, static fn (): string => ''));
    }

    public function testReadDiagnosesAForeignStorageFormatAsAMiss(): void
    {
        $store = new MemoryCache();
        $store->set('key', ForeignFormatEntry::create());
        $observer = new RecordingObserver();

        $missed = (new GuardedCache($store, $observer))->read('key', null, static fn (): string => '');

        self::assertNull($missed);
        self::assertSame([CacheEvent::CorruptEntry], $observer->events);
    }

    public function testReadPropagatesBackendFailuresWithoutAClassifier(): void
    {
        $this->expectException(CacheBackendFailure::class);

        (new GuardedCache(new FailingCache()))->read('key', null, static fn (): string => '');
    }

    public function testReadBypassesOnlyClassifiedBackendFailures(): void
    {
        $observer = new RecordingObserver();

        $entry = (new GuardedCache(new FailingCache(), $observer))
            ->read('key', new DefaultBackendErrorClassifier(), static fn (): string => '');

        self::assertNull($entry);
        self::assertSame([CacheEvent::BackendBypassed], $observer->events);
    }

    public function testWriteStoresTheEntryAndReportsIt(): void
    {
        $store = new MemoryCache();
        $observer = new RecordingObserver();
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0));

        (new GuardedCache($store, $observer))->write('key', $entry, null);

        self::assertSame($entry, $store->get('key', static fn (): string => ''));
        self::assertSame([CacheEvent::Stored], $observer->events);
    }

    public function testWritePropagatesBackendFailuresWithoutAClassifier(): void
    {
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0));

        $this->expectException(CacheBackendFailure::class);

        (new GuardedCache(new FailingCache()))->write('key', $entry, null);
    }

    public function testWriteSkipsOnlyClassifiedBackendFailures(): void
    {
        $observer = new RecordingObserver();
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0));

        (new GuardedCache(new FailingCache(), $observer))->write('key', $entry, new DefaultBackendErrorClassifier());

        self::assertSame([CacheEvent::BackendBypassed], $observer->events);
    }
}
