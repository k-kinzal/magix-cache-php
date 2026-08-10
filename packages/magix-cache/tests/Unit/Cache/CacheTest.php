<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cache::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheTest extends TestCase
{
    public function testGetReturnsCacheEntryFromImplementation(): void
    {
        $entry = new CacheEntry('value', 120.0);
        $typeWitness = static fn (): string => '';
        $cache = $this->createMock(Cache::class);
        $cache
            ->expects(self::once())
            ->method('get')
            ->with('key', $typeWitness)
            ->willReturn($entry);

        self::assertSame($entry, $cache->get('key', $typeWitness));
    }

    public function testSetPassesCompleteCacheEntryToImplementation(): void
    {
        $entry = new CacheEntry('value', 120.0);
        $cache = $this->createMock(Cache::class);
        $cache
            ->expects(self::once())
            ->method('set')
            ->with('key', $entry);

        $cache->set('key', $entry);
    }
}
