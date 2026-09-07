<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheOperation::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
final class CacheOperationTest extends TestCase
{
    public function testKeyReturnsTheResolvedStorageKey(): void
    {
        self::assertSame('key', (new CacheOperation('key', static fn (): float => 100.0))->key());
    }

    public function testNowReadsTheClockOnEveryCall(): void
    {
        $now = 100.0;
        $operation = new CacheOperation('key', static function () use (&$now): float {
            return $now;
        });

        self::assertSame(100.0, $operation->now());

        $now = 101.0;
        self::assertSame(101.0, $operation->now());
    }

    public function testOriginSucceededFollowsTheStampedBaseTime(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);

        self::assertFalse($operation->originSucceeded());

        $operation->stampBaseTime(100.0);

        self::assertTrue($operation->originSucceeded());
    }

    public function testBaseTimeIsTheSingleStampedInstant(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->stampBaseTime(100.5);

        self::assertSame(100.5, $operation->baseTime());
    }

    public function testStampBaseTimeMarksTheOriginSuccess(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->stampBaseTime(100.0);

        self::assertSame(100.0, $operation->baseTime());
        self::assertTrue($operation->originSucceeded());
    }

    public function testRetainStaleKeepsTheCandidateWithItsExpiredExpiration(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $operation->retainStale($stale, 130.0);

        self::assertSame($stale, $operation->stale());
        self::assertSame(90.0, $operation->stale()->metadata->expiresAt);
    }

    public function testStaleIsNullUntilTheLookupRetainsACandidate(): void
    {
        self::assertNull((new CacheOperation('key', static fn (): float => 100.0))->stale());
    }

    public function testStaleWithinJudgesRetentionAndAgeAtOneInstant(): void
    {
        $now = 100.0;
        $operation = new CacheOperation('key', static function () use (&$now): float {
            return $now;
        });
        $stale = Cached::of('stale', new CacheMetadata(expiresAt: 90.0));
        $operation->retainStale($stale, 130.0);

        self::assertSame($stale, $operation->staleWithin(30));
        self::assertNull($operation->staleWithin(10), 'a candidate exactly at its age limit is rejected');

        $now = 130.0;
        self::assertNull($operation->staleWithin(300), 'a candidate at its retention limit is rejected');
    }

    public function testStaleWithinIsNullWithoutARetainedCandidate(): void
    {
        self::assertNull((new CacheOperation('key', static fn (): float => 100.0))->staleWithin(300));
    }

    public function testSuppressStoreMarksTheOperation(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->suppressStore();

        self::assertTrue($operation->storeSuppressed());
    }

    public function testStoreSuppressedIsFalseByDefault(): void
    {
        self::assertFalse((new CacheOperation('key', static fn (): float => 100.0))->storeSuppressed());
    }

    public function testExtendRetentionRequestsOnlyEverGrow(): void
    {
        $operation = new CacheOperation('key', static fn (): float => 100.0);
        $operation->extendRetention(150.0);
        $operation->extendRetention(120.0);

        self::assertSame(150.0, $operation->retention());
    }

    public function testRetentionIsNullUntilAStrategyRequestsOne(): void
    {
        self::assertNull((new CacheOperation('key', static fn (): float => 100.0))->retention());
    }
}
