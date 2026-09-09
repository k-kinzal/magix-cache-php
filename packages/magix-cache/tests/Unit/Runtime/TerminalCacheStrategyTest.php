<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\GuardedCache;
use Magix\Cache\Runtime\TerminalCacheStrategy;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\RecordingObserver;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(TerminalCacheStrategy::class)]
#[UsesClass(StaleIfError::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(\Magix\Cache\Runtime\CacheEntryConverter::class)]
#[UsesClass(GuardedCache::class)]
#[UsesClass(\Magix\Cache\Runtime\OriginOverrides::class)]
#[UsesClass(\Magix\Cache\Runtime\Policy\PolicySemantics::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(NextCacheStrategy::class)]
#[UsesClass(OriginFailure::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(\Magix\Cache\Strategy\CacheRead::class)]
final class TerminalCacheStrategyTest extends TestCase
{
    public function testGetExposesAStoredFreshCandidate(): void
    {
        $storage = new MemoryCache();
        $storage->set('key', new CacheEntry('stored', new CacheMetadata(expiresAt: 150.0)));
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($storage),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            policy: new CachePolicy(ttl: 60),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertSame('stored', $terminal->get($operation, NextCacheStrategy::end())?->cached->value());
    }

    public function testGetRetainsAnExpiredEntryStillInsideItsRetention(): void
    {
        $storage = new MemoryCache();
        $storage->set('key', new CacheEntry('stale', new CacheMetadata(expiresAt: 90.0), retainedUntil: 400.0));
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($storage),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            policy: new CachePolicy(ttl: 60),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $read = $terminal->get($operation, NextCacheStrategy::end());
        self::assertSame('stale', $read?->cached->value());
        self::assertSame(90.0, $read->cached->metadata->expiresAt);
        self::assertSame(400.0, $read->retainedUntil);
    }

    public function testFetchStampsTheBaseTimeRightAfterTheOriginSucceeds(): void
    {
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache(new MemoryCache()),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            policy: new CachePolicy(ttl: 60),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $result = $terminal->fetch($operation, NextCacheStrategy::end());

        self::assertInstanceOf(OriginResult::class, $result);
        self::assertSame('origin', $result->cached->value());
        self::assertSame(100.0, $result->baseTime);
        self::assertSame(160.0, $result->cached->metadata->expiresAt);
    }

    public function testFetchReportsTheOriginalFailureWithoutAnsweringIt(): void
    {
        $error = new UpstreamUnavailable('down');
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache(new MemoryCache()),
            classifier: null,
            origin: static fn (): Cached => throw $error,
            policy: new CachePolicy(ttl: 60),
        );
        $result = $terminal->fetch(new CacheOperation('key', static fn (): float => 100.0), NextCacheStrategy::end());

        self::assertInstanceOf(OriginFailure::class, $result);
        self::assertSame($error, $result->error);
    }

    public function testSetWritesTheRequestedRetention(): void
    {
        $storage = new MemoryCache();
        $terminal = new TerminalCacheStrategy(
            cache: new GuardedCache($storage),
            classifier: null,
            origin: static fn (): Cached => Cached::of('origin'),
            policy: new CachePolicy(ttl: 60),
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $terminal->set($operation, new CacheWrite(Cached::of('value', new CacheMetadata(expiresAt: 160.0)), 500.0), NextCacheStrategy::end());

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
            policy: new CachePolicy(ttl: 60),
            observer: $observer,
        );
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        $terminal->set($operation, new CacheWrite(Cached::of('value')), NextCacheStrategy::end());

        self::assertSame([CacheEvent::StoreSkipped], $observer->events);
    }

    public function testFetchDoesNotClassifyAFailureAfterOriginSuccessAsAnOriginFailure(): void
    {
        $error = new RuntimeException('clock failed');
        $terminal = new TerminalCacheStrategy(new GuardedCache(new MemoryCache()), null, static fn (): Cached => Cached::of('origin'), new CachePolicy(ttl: 60));
        $operation = new CacheOperation('key', static fn (): float => throw $error);

        $this->expectExceptionObject($error);
        $terminal->fetch($operation, NextCacheStrategy::end());
    }
}
