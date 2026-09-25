<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\CacheHandlers;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(StaleIfErrorCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(CacheRead::class)]
#[UsesClass(CacheWrite::class)]
#[UsesNamespace('Magix\Cache')]
final class StaleIfErrorCacheStrategyTest extends TestCase
{
    public function testGetRetainsTheCandidateForFallback(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(maxAge: 30, exceptions: [UpstreamUnavailable::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $key = 'key';
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $next = new CacheHandlers($stale, Cached::of('unused'), retainedUntil: 130.0, error: new UpstreamUnavailable('down'));

        $read = $strategy->get($key, $next->get(...));
        $answer = $strategy->fetch($key, $next->fetch(...))->wait();

        self::assertSame($stale, $read?->cached);
        self::assertSame($stale, $answer);
        self::assertSame(90.0, $answer->metadata->expiresAt);
    }

    /**
     * @return iterable<string, array{float, float, int}>
     */
    public static function providerRejectedWindows(): iterable
    {
        yield 'still fresh' => [89.0, 130.0, 30];
        yield 'age limit reached' => [120.0, 130.0, 30];
        yield 'physical limit reached' => [130.0, 130.0, 300];
        yield 'zero window' => [90.0, 130.0, 0];
    }

    #[DataProvider('providerRejectedWindows')]
    public function testFetchJudgesAgeAndRetentionAtFailureTime(float $now, float $retention, int $age): void
    {
        $clock = new \Tests\Fixture\MutableClock(80.0);
        $key = 'key';
        $strategy = new StaleIfErrorCacheStrategy($age, [UpstreamUnavailable::class], clock: $clock);
        $error = new UpstreamUnavailable('down');
        $next = new CacheHandlers(
            Cached::of('old', new CacheMetadata(expiresAt: 90.0)),
            Cached::of('unused'),
            retainedUntil: $retention,
            error: $error,
        );
        $strategy->get($key, $next->get(...));
        $clock->time = $now;

        $this->expectExceptionObject($error);
        $strategy->fetch($key, $next->fetch(...))->wait();
    }

    public function testFetchDeclinesAnUnacceptedFailure(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [UpstreamUnavailable::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $key = 'key';
        $error = new RuntimeException('other');
        $next = new CacheHandlers(
            Cached::of('old', new CacheMetadata(expiresAt: 90.0)),
            Cached::of('unused'),
            retainedUntil: 400.0,
            error: $error,
        );
        $strategy->get($key, $next->get(...));

        $this->expectExceptionObject($error);
        $strategy->fetch($key, $next->fetch(...))->wait();
    }

    public function testFetchCannotUseAnotherStrategyInstancesCandidate(): void
    {
        $first = new StaleIfErrorCacheStrategy(300, [UpstreamUnavailable::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $second = new StaleIfErrorCacheStrategy(300, [UpstreamUnavailable::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $key = 'key';
        $error = new UpstreamUnavailable('down');
        $next = new CacheHandlers(
            Cached::of('old', new CacheMetadata(expiresAt: 90.0)),
            Cached::of('unused'),
            retainedUntil: 400.0,
            error: $error,
        );
        $first->get($key, $next->get(...));

        $this->expectExceptionObject($error);
        $second->fetch($key, $next->fetch(...))->wait();
    }

    public function testFetchPassesSuccessThrough(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $cached = Cached::of('origin');
        $next = new CacheHandlers(null, $cached);

        $result = $strategy->fetch('key', $next->fetch(...))->wait();

        self::assertSame($cached, $result);
    }

    public function testSetExtendsOnlyTheWriteRequestsPhysicalRetention(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $key = 'key';
        $terminal = new CacheHandlers(null, Cached::of('unused'));
        $request = new CacheWrite(Cached::of('value', new CacheMetadata(expiresAt: 160.0)));

        $strategy->set($key, $request, $terminal->set(...));

        self::assertSame(460.0, $terminal->stored?->retainedUntil);
        self::assertSame($request->cached, $terminal->stored->cached);
        self::assertNull($request->retainedUntil);
    }

    public function testSetPreservesALongerRequestAndUnconstrainedResults(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $key = 'key';
        $terminal = new CacheHandlers(null, Cached::of('unused'));
        $request = new CacheWrite(Cached::of('value', new CacheMetadata(expiresAt: 160.0)), 500.0);
        $strategy->set($key, $request, $terminal->set(...));
        self::assertSame(500.0, $terminal->stored?->retainedUntil);

        $strategy->set($key, new CacheWrite(Cached::of('value')), $terminal->set(...));
        self::assertNotNull($terminal->stored);
        self::assertNull($terminal->stored->retainedUntil);
    }

    public function testFetchPreservesTheInnerSelectionWhenBothStrategiesHaveEligibleCandidates(): void
    {
        $key = 'key';
        $outer = new StaleIfErrorCacheStrategy(300, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $inner = new StaleIfErrorCacheStrategy(30, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0));
        $outerCandidate = Cached::of('outer', new CacheMetadata(expiresAt: 80.0));
        $innerCandidate = Cached::of('inner', new CacheMetadata(expiresAt: 90.0));
        $outer->get($key, static fn (): CacheRead => new CacheRead($outerCandidate, 400.0));
        $inner->get($key, static fn (): CacheRead => new CacheRead($innerCandidate, 130.0));
        $chain = new \Magix\Cache\Strategy\ComposedCacheStrategy($outer, $inner);

        self::assertSame($innerCandidate, $chain->fetch($key, static fn (): Promise => throw new RuntimeException('origin failed'))->wait());
    }

    public function testSetReportsAndSuppressesAWriteOfItsServedCandidate(): void
    {
        $observer = new \Tests\Fixture\RecordingObserver();
        $key = 'key';
        $stale = Cached::of('retained', new CacheMetadata(expiresAt: 90.0));
        $terminal = new CacheHandlers($stale, Cached::of('unused'), retainedUntil: 130.0, error: new UpstreamUnavailable('down'));
        $next = $terminal;
        $strategy = new StaleIfErrorCacheStrategy(30, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0), observer: $observer);
        $strategy->get($key, $next->get(...));
        $recovered = $strategy->fetch($key, $next->fetch(...))->wait();

        self::assertSame($stale, $recovered);
        self::assertSame([], $observer->events);
        $strategy->set($key, new CacheWrite($recovered), $next->set(...));
        self::assertNull($terminal->stored);
        self::assertSame([\Magix\Cache\Observation\CacheEvent::StaleServed], $observer->events);
    }

    public function testSetStoresAReplacementForItsCandidateWithoutReportingStaleServed(): void
    {
        $observer = new \Tests\Fixture\RecordingObserver();
        $key = 'key';
        $stale = Cached::of('retained', new CacheMetadata(expiresAt: 90.0));
        $terminal = new CacheHandlers($stale, Cached::of('unused'), retainedUntil: 130.0, error: new UpstreamUnavailable('down'));
        $next = $terminal;
        $strategy = new StaleIfErrorCacheStrategy(30, [RuntimeException::class], clock: new \Tests\Fixture\MutableClock(100.0), observer: $observer);
        $strategy->get($key, $next->get(...));
        $strategy->fetch($key, $next->fetch(...))->wait();

        $replacement = Cached::of('new recovery', new CacheMetadata(expiresAt: 160.0));
        $strategy->set($key, new CacheWrite($replacement), $next->set(...));
        self::assertSame([], $observer->events);
        self::assertSame($replacement, $terminal->stored?->cached);
        self::assertSame(190.0, $terminal->stored->retainedUntil);
    }
}
