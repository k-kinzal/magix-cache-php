<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixture\AnsweringStrategy;
use Tests\Fixture\UpstreamUnavailable;

#[CoversClass(StaleIfErrorCacheStrategy::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(CacheOperation::class)]
#[UsesClass(CacheRead::class)]
#[UsesClass(CacheWrite::class)]
#[UsesClass(CacheAnswer::class)]
#[UsesClass(OriginFailure::class)]
#[UsesClass(OriginResult::class)]
#[UsesClass(NextCacheStrategy::class)]
final class StaleIfErrorCacheStrategyTest extends TestCase
{
    public function testGetRetainsTheCandidateForFetch(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(maxAge: 30, exceptions: [UpstreamUnavailable::class]);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $next = NextCacheStrategy::of(new AnsweringStrategy($stale, Cached::of('unused'), failure: new UpstreamUnavailable('down'), retainedUntil: 130.0));

        $read = $strategy->get($operation, $next);
        $answer = $strategy->fetch($operation, $next);

        self::assertSame($stale, $read?->cached);
        self::assertInstanceOf(CacheAnswer::class, $answer);
        self::assertSame($stale, $answer->cached);
        self::assertSame(90.0, $answer->cached->metadata->expiresAt);
        self::assertSame('StaleServed', $answer->event);
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
        $time = 80.0;
        $operation = new CacheOperation('key', static function () use (&$time): float {
            return $time;
        });
        $strategy = new StaleIfErrorCacheStrategy($age, [UpstreamUnavailable::class]);
        $error = new UpstreamUnavailable('down');
        $next = NextCacheStrategy::of(new AnsweringStrategy(
            Cached::of('old', new CacheMetadata(expiresAt: 90.0)),
            Cached::of('unused'),
            failure: $error,
            retainedUntil: $retention,
        ));
        $strategy->get($operation, $next);
        $time = $now;

        $result = $strategy->fetch($operation, $next);

        self::assertInstanceOf(OriginFailure::class, $result);
        self::assertSame($error, $result->error);
    }

    public function testFetchPassesAnUnacceptedFailureThroughUnchanged(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [UpstreamUnavailable::class]);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $error = new RuntimeException('other');
        $next = NextCacheStrategy::of(new AnsweringStrategy(
            Cached::of('old', new CacheMetadata(expiresAt: 90.0)),
            Cached::of('unused'),
            failure: $error,
            retainedUntil: 400.0,
        ));
        $strategy->get($operation, $next);

        $result = $strategy->fetch($operation, $next);

        self::assertInstanceOf(OriginFailure::class, $result);
        self::assertSame($error, $result->error);
    }

    public function testFetchCannotUseAnotherStrategyInstancesCandidate(): void
    {
        $first = new StaleIfErrorCacheStrategy(300, [UpstreamUnavailable::class]);
        $second = new StaleIfErrorCacheStrategy(300, [UpstreamUnavailable::class]);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $error = new UpstreamUnavailable('down');
        $next = NextCacheStrategy::of(new AnsweringStrategy(
            Cached::of('old', new CacheMetadata(expiresAt: 90.0)),
            Cached::of('unused'),
            failure: $error,
            retainedUntil: 400.0,
        ));
        $first->get($operation, $next);

        $result = $second->fetch($operation, $next);

        self::assertInstanceOf(OriginFailure::class, $result);
        self::assertSame($error, $result->error);
    }

    public function testFetchPassesSuccessThrough(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [RuntimeException::class]);
        $cached = Cached::of('origin');
        $next = NextCacheStrategy::of(new AnsweringStrategy(null, $cached));

        $result = $strategy->fetch(new CacheOperation('key', static fn (): float => 100.0), $next);

        self::assertInstanceOf(OriginResult::class, $result);
        self::assertSame($cached, $result->cached);
        self::assertSame(100.0, $result->baseTime);
    }

    public function testSetExtendsOnlyTheWriteRequestsPhysicalRetention(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [RuntimeException::class]);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $terminal = new AnsweringStrategy(null, Cached::of('unused'));
        $request = new CacheWrite(Cached::of('value', new CacheMetadata(expiresAt: 160.0)));

        $strategy->set($operation, $request, NextCacheStrategy::of($terminal));

        self::assertSame(460.0, $terminal->stored?->retainedUntil);
        self::assertSame($request->cached, $terminal->stored->cached);
        self::assertNull($request->retainedUntil);
    }

    public function testSetPreservesALongerRequestAndUnconstrainedResults(): void
    {
        $strategy = new StaleIfErrorCacheStrategy(300, [RuntimeException::class]);
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $terminal = new AnsweringStrategy(null, Cached::of('unused'));
        $request = new CacheWrite(Cached::of('value', new CacheMetadata(expiresAt: 160.0)), 500.0);
        $strategy->set($operation, $request, NextCacheStrategy::of($terminal));
        self::assertSame(500.0, $terminal->stored?->retainedUntil);

        $strategy->set($operation, new CacheWrite(Cached::of('value')), NextCacheStrategy::of($terminal));
        self::assertNotNull($terminal->stored);
        self::assertNull($terminal->stored->retainedUntil);
    }
}
