<?php

declare(strict_types=1);

namespace Tests\Integration;

use GuzzleHttp\Promise\Utils;
use Magix\Cache\AsyncCached;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\AsyncQuery;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;
use Tests\Fixture\PendingResult;
use Tests\Fixture\RecordingObserver;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(CacheRuntime::class)]
#[CoversClass(AsyncCached::class)]
#[CoversTrait(\Magix\Cache\Cacheable::class)]
#[UsesNamespace('Magix\Cache')]
final class AsyncRuntimeTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        CacheRuntimeRegistry::reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        CacheRuntimeRegistry::reset();
    }

    public function testWriteRunsAfterResolutionWithoutWaitingAndHitKeepsMetadata(): void
    {
        $clock = new MutableClock(100.0);
        $observer = new RecordingObserver();
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), $clock, observer: $observer));
        $source = new PendingResult('value');
        $query = new AsyncQuery($source);
        $pending = $query->fetch();
        self::assertSame(0, $source->waits);
        Utils::queue()->run();
        self::assertSame(1, $query->calls);
        self::assertSame([CacheEvent::Miss], $observer->events);
        $clock->advance(4.25);
        $source->complete();
        Utils::queue()->run();
        self::assertSame(0, $source->waits);
        $result = $pending->toCached();
        self::assertSame(124.25, $result->metadata->expiresAt);
        self::assertSame(['source'], $result->metadata->tags);
        self::assertSame(['origin'], $result->metadata->reasons);
        $clock->advance(1.0);
        self::assertEquals($result, $query->fetch()->toCached());
        self::assertSame(1, $query->calls);
        self::assertContains(CacheEvent::FreshHit, $observer->events);
        self::assertSame($result, $pending->toCached());
    }

    public function testSynchronousBoundaryWaitsForAnAsyncOriginAndAsyncBoundaryAcceptsCached(): void
    {
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $source = new PendingResult('value');
        $query = new AsyncQuery($source);
        self::assertSame('value', $query->synchronous()->value());
        self::assertSame(1, $source->waits);
        self::assertSame('immediate', $query->immediate()->value());
    }

    public function testNestedAsyncBoundariesComposeBeforeSynchronization(): void
    {
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $source = new PendingResult('value');
        $query = new AsyncQuery($source);
        $result = $query->composed();
        Utils::queue()->run();
        self::assertSame(2, $query->calls);
        self::assertSame(0, $source->waits);
        self::assertSame(['value', 'value'], $result->value());
        self::assertSame(120.0, $result->toCached()->metadata->expiresAt);
        self::assertSame(1, $source->waits);
    }

    public function testDelayedOriginRejectionUsesRetainedCandidateAtFailureTime(): void
    {
        $clock = new MutableClock(100.0);
        $observer = new RecordingObserver();
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), $clock, observer: $observer));
        $query = new AsyncQuery(new PendingResult('retained'));
        $stored = $query->fetch()->toCached();
        $clock->time = 121.0;
        $source = new PendingResult('unused');
        $query->source = $source;
        $result = $query->fetch();
        Utils::queue()->run();
        $clock->time = 125.0;
        $source->fail(new UpstreamUnavailable('down'));
        Utils::queue()->run();
        self::assertEquals($stored, $result->toCached());
        self::assertSame(120.0, $result->toCached()->metadata->expiresAt);
        self::assertContains(CacheEvent::StaleServed, $observer->events);
        self::assertSame(0, $source->waits);
    }

    public function testUnansweredDelayedFailurePropagatesTheSameException(): void
    {
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $source = new PendingResult('unused');
        $query = new AsyncQuery($source);
        $result = $query->fetch();
        Utils::queue()->run();
        $error = new UpstreamUnavailable('down');
        $source->fail($error);
        $this->expectExceptionObject($error);
        $result->toCached();
    }

    public function testShortCircuitCompletesWithoutInvokingTheOrigin(): void
    {
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), new MutableClock(100.0)));
        $source = new PendingResult('unused');
        $query = new AsyncQuery($source);
        self::assertSame('immediate', $query->shortCircuit()->value());
        self::assertSame(0, $query->calls);
        self::assertSame(0, $source->waits);
        self::assertSame('immediate', $query->shortCircuit()->value());
    }
    public function testFailedFetchNeverEntersTheWriteChain(): void
    {
        $cache = $this->createMock(\Magix\Cache\Cache\Cache::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::never())->method('set');
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime($cache, new MutableClock(100.0)));
        $source = new PendingResult('unused');
        $result = (new AsyncQuery($source))->fetch();
        Utils::queue()->run();
        $error = new UpstreamUnavailable('down');
        $source->fail($error);
        $this->expectExceptionObject($error);
        $result->value();
    }

    public function testWriteFailureRejectsTheResultAfterOriginCompletion(): void
    {
        $error = new \Magix\Cache\Cache\CacheBackendFailure('write failed');
        $cache = $this->createMock(\Magix\Cache\Cache\Cache::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())->method('set')->willThrowException($error);
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime($cache, new MutableClock(100.0)));
        $source = new PendingResult('value');
        $result = (new AsyncQuery($source))->fetch();
        Utils::queue()->run();
        self::assertSame(0, $source->waits);
        $source->complete();
        $this->expectExceptionObject($error);
        $result->toCached();
    }

    public function testOverlappingInvocationsKeepIndependentStaleCandidates(): void
    {
        $clock = new MutableClock(100.0);
        CacheRuntimeRegistry::register(CacheRuntimeRegistry::DEFAULT_NAME, new CacheRuntime(new MemoryCache(), $clock));
        $query = new AsyncQuery(new PendingResult('first'));
        self::assertSame('first', $query->fetch(1)->value());
        $query->source = new PendingResult('second');
        self::assertSame('second', $query->fetch(2)->value());
        $clock->time = 125.0;
        $source = new PendingResult('unused');
        $query->source = $source;
        $first = $query->fetch(1);
        $second = $query->fetch(2);
        Utils::queue()->run();
        self::assertSame(0, $source->waits);
        $source->fail(new UpstreamUnavailable('down'));
        self::assertSame('second', $second->value());
        self::assertSame('first', $first->value());
        self::assertSame(120.0, $first->toCached()->metadata->expiresAt);
        self::assertSame(120.0, $second->toCached()->metadata->expiresAt);
    }

}
