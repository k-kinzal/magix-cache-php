<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheOperationTerminal;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MemoryCache;
use Tests\Fixture\MutableClock;

#[CoversClass(CacheOperationTerminal::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(CacheGet::class)]
#[UsesClass(CacheSet::class)]
#[UsesClass(OriginFetch::class)]
#[UsesClass(OriginFetchOutcome::class)]
#[UsesClass(OriginFetchResult::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheOperationTerminalTest extends TestCase
{
    public function testGetReadsFromTerminalCache(): void
    {
        $cache = new MemoryCache();
        $entry = new CacheEntry('value', 120.0);
        $cache->set('key', $entry);
        $terminal = new CacheOperationTerminal($cache, static fn (): string => '');

        self::assertSame($entry, $terminal->get(new CacheGet('key', new MutableClock(100.0))));
    }

    public function testFetchInvokesOrigin(): void
    {
        $terminal = new CacheOperationTerminal(new MemoryCache(), static fn (): string => '');
        $fetch = new OriginFetch(
            'key',
            static fn (): Cached => Cached::of('origin'),
            null,
            new MutableClock(100.0),
        );

        self::assertSame('origin', $terminal->fetch($fetch)->originValue()->value());
    }

    public function testSetWritesToTerminalCache(): void
    {
        $cache = new MemoryCache();
        $terminal = new CacheOperationTerminal($cache, static fn (): string => '');
        $entry = new CacheEntry('value', 120.0);

        $terminal->set(new CacheSet('key', $entry));

        self::assertSame($entry, $cache->get('key', static fn (): string => ''));
    }
}
