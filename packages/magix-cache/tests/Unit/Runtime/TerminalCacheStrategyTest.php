<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\TerminalCacheStrategy;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\NextCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\RecordingObserver;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(TerminalCacheStrategy::class)]
final class TerminalCacheStrategyTest extends TestCase
{
    public function testGetReturnsAFreshHitAndRetainsNoCandidate(): void
    {
        $storage = new MemoryCache();
        $storage->set('key', new CacheEntry('stored', new CacheMetadata(expiresAt: 150.0)));
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($storage),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('stored', $terminal->get($operation, NextCacheStrategy::end())?->value());
        self::assertNull($operation->stale());
    }

    public function testGetRetainsAnExpiredEntryStillInsideItsRetention(): void
    {
        $storage = new MemoryCache();
        $storage->set('key', new CacheEntry('stale', new CacheMetadata(expiresAt: 90.0), retainedUntil: 400.0));
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($storage),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertNull($terminal->get($operation, NextCacheStrategy::end()));
        self::assertSame('stale', $operation->stale()?->value());
        self::assertSame(90.0, $operation->stale()->metadata->expiresAt);
    }

    public function testFetchStampsTheBaseTimeRightAfterTheOriginSucceeds(): void
    {
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache(new MemoryCache()),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $result = $terminal->fetch($operation, NextCacheStrategy::end());

        self::assertSame('origin', $result->value());
        self::assertSame(100.0, $operation->baseTime());
        self::assertFalse($operation->storeSuppressed());
    }

    public function testFetchServesTheDeclaredStaleFallbackAndSuppressesTheStore(): void
    {
        $observer = new RecordingObserver();
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache(new MemoryCache(), $observer),
            classifier: null,
            origin: static fn (): Cached => throw new UpstreamUnavailable('down'),
            staleIfError: new StaleIfError(maxAge: 300, exceptions: [UpstreamUnavailable::class]),
            observer: $observer,
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->retainStale(Cached::of('stale', new CacheMetadata(expiresAt: 90.0)), 400.0);

        $result = $terminal->fetch($operation, NextCacheStrategy::end());

        self::assertSame('stale', $result->value());
        self::assertTrue($operation->storeSuppressed());
        self::assertFalse($operation->originSucceeded());
        self::assertContains(CacheEvent::StaleServed, $observer->events);
    }

    public function testSetWritesWithTheLargestRequestedRetention(): void
    {
        $storage = new MemoryCache();
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($storage),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            staleIfError: new StaleIfError(maxAge: 100, exceptions: [UpstreamUnavailable::class]),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->extendRetention(500.0);

        $terminal->set($operation, Cached::of('value', new CacheMetadata(expiresAt: 160.0)), NextCacheStrategy::end());

        $entry = $storage->get('key', static fn (): string => 'value');

        self::assertSame(500.0, $entry?->retainedUntil);
        self::assertSame(160.0, $entry->expiresAt);
    }

    public function testSetSkipsAnUnstorableResult(): void
    {
        $observer = new RecordingObserver();
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache(new MemoryCache(), $observer),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            observer: $observer,
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $terminal->set($operation, Cached::of('value'), NextCacheStrategy::end());

        self::assertSame([CacheEvent::StoreSkipped], $observer->events);
    }

    public function testRetentionTakesTheDeclaredBehaviorAndTheLargerRequest(): void
    {
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache(new MemoryCache()),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            staleIfError: new StaleIfError(maxAge: 100, exceptions: [UpstreamUnavailable::class]),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $result = Cached::of('value', new CacheMetadata(expiresAt: 160.0));

        self::assertSame(260.0, $terminal->retention($operation, $result));

        $operation->extendRetention(500.0);

        self::assertSame(500.0, $terminal->retention($operation, $result));
        self::assertNull(
            $terminal->retention(new CacheOperation('key', static fn (): float => 100.0), Cached::of('value')),
            'no expiration and no request means no retention',
        );
    }
}
