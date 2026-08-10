<?php

declare(strict_types=1);

namespace Tests\Unit\Cache\PSR16;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cache\PSR16\SimpleCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Tests\Fixture\MutableClock;

#[CoversClass(SimpleCache::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class SimpleCacheTest extends TestCase
{
    public function testGetReturnsCacheEntry(): void
    {
        $psr16 = new Psr16Cache(new ArrayAdapter());
        $cache = new SimpleCache($psr16, new MutableClock(4_000_000_000.0));
        $entry = new CacheEntry('value', 4_000_000_020.0);
        $typeWitness = static fn (): string => '';
        $psr16->set('generated-key', $entry, 20);

        self::assertEquals($entry, $cache->get('generated-key', $typeWitness));
    }

    public function testSetPersistsCacheEntry(): void
    {
        $psr16 = new Psr16Cache(new ArrayAdapter());
        $cache = new SimpleCache($psr16, new MutableClock(4_000_000_000.0));
        $entry = new CacheEntry('value', 4_000_000_020.0);

        $cache->set('generated-key', $entry);

        self::assertEquals($entry, $psr16->get('generated-key'));
    }

    public function testSetUsesPhysicalRetentionAsPsrTtl(): void
    {
        $psr16 = $this->createMock(CacheInterface::class);
        $entry = new CacheEntry(
            value: 'value',
            expiresAt: 4_000_000_020.0,
            retainedUntil: 4_000_000_050.0,
        );
        $psr16->expects($this->once())
            ->method('set')
            ->with('generated-key', $entry, 50)
            ->willReturn(true);

        (new SimpleCache($psr16, new MutableClock(4_000_000_000.0)))
            ->set('generated-key', $entry);
    }
}
