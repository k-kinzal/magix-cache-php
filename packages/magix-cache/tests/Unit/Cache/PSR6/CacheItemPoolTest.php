<?php

declare(strict_types=1);

namespace Tests\Unit\Cache\PSR6;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cache\PSR6\CacheItemPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(CacheItemPool::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheItemPoolTest extends TestCase
{
    public function testGetReturnsCacheEntry(): void
    {
        $pool = new ArrayAdapter();
        $cache = new CacheItemPool($pool);
        $entry = new CacheEntry('value', 4_000_000_020.0);
        $typeWitness = static fn (): string => '';
        $item = $pool->getItem('generated-key');
        $item->set($entry);
        $item->expiresAfter(20);
        $pool->save($item);

        self::assertEquals($entry, $cache->get('generated-key', $typeWitness));
    }

    public function testSetPersistsCacheEntry(): void
    {
        $pool = new ArrayAdapter();
        $cache = new CacheItemPool($pool);
        $entry = new CacheEntry('value', 4_000_000_020.0);

        $cache->set('generated-key', $entry);

        self::assertEquals($entry, $pool->getItem('generated-key')->get());
    }

    public function testSetRetainsEntryAfterItsLogicalExpiration(): void
    {
        $now = time();
        $pool = new ArrayAdapter();
        $entry = new CacheEntry(
            value: 'value',
            expiresAt: $now - 10.0,
            retainedUntil: $now + 60.0,
        );

        (new CacheItemPool($pool))->set('generated-key', $entry);

        $item = $pool->getItem('generated-key');
        self::assertTrue($item->isHit());
        self::assertEquals($entry, $item->get());
    }
}
