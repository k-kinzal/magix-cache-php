<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Strategy;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Strategy\CompositeCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;
use Tests\Fixture\RecordingCacheStrategy;

#[CoversClass(CompositeCacheStrategy::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(\Magix\Cache\Runtime\Operation\OriginFetchOutcome::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CompositeCacheStrategyTest extends TestCase
{
    public function testGetComposesInMiddlewareOrder(): void
    {
        $events = [];
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };
        $strategy = new CompositeCacheStrategy(
            new RecordingCacheStrategy('outer', $record),
            new RecordingCacheStrategy('inner', $record),
        );
        $entry = new CacheEntry('value', 120.0);

        self::assertSame($entry, $strategy->get(
            new CacheGet('key', new MutableClock(100.0)),
            static fn (): CacheEntry => $entry,
        ));
        self::assertSame(['inner', 'outer'], $events);
    }

    public function testFetchComposesInMiddlewareOrder(): void
    {
        $events = [];
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };
        $strategy = new CompositeCacheStrategy(
            new RecordingCacheStrategy('outer', $record),
            new RecordingCacheStrategy('inner', $record),
        );
        $result = new OriginFetchResult(Cached::of('value'));
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('value'),
            null,
            new MutableClock(100.0),
        );

        self::assertSame($result, $strategy->fetch($fetch, static fn (): OriginFetchResult => $result));
        self::assertSame(['inner', 'outer'], $events);
    }

    public function testSetComposesInMiddlewareOrder(): void
    {
        $events = [];
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };
        $strategy = new CompositeCacheStrategy(
            new RecordingCacheStrategy('outer', $record),
            new RecordingCacheStrategy('inner', $record),
        );

        $strategy->set(
            new CacheSet('key', new CacheEntry('value', 120.0)),
            static function (): void {
            },
        );

        self::assertSame(['inner', 'outer'], $events);
    }
}
