<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\OriginFetch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(OriginFetch::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class OriginFetchTest extends TestCase
{
    public function testInvokeRunsOrigin(): void
    {
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('origin'),
            null,
            new MutableClock(100.0),
        );

        self::assertSame('origin', $fetch->invoke()->value());
    }

    public function testStaleReturnsCandidate(): void
    {
        $stale = new CacheEntry('stale', 90.0, retainedUntil: 120.0);
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('origin'),
            $stale,
            new MutableClock(100.0),
        );

        self::assertSame($stale, $fetch->stale());
    }

    public function testWithKeyPreservesOriginAndStaleCandidate(): void
    {
        $stale = new CacheEntry('stale', 90.0, retainedUntil: 120.0);
        $fetch = new OriginFetch(
            'original',
            static fn (): Cached => Cached::of('origin'),
            $stale,
            new MutableClock(100.0),
        );

        $transformed = $fetch->withKey('transformed');

        self::assertSame('transformed', $transformed->key);
        self::assertSame('origin', $transformed->invoke()->value());
        self::assertSame($stale, $transformed->stale());
    }

    public function testNowUsesRuntimeClock(): void
    {
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('origin'),
            null,
            new MutableClock(100.5),
        );

        self::assertSame(100.5, $fetch->now());
    }
}
