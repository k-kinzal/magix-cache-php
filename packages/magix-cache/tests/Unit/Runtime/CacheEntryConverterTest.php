<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheEntryConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEntryConverter::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(Visibility::class)]
final class CacheEntryConverterTest extends TestCase
{
    public function testToCachedKeepsTheExactStoredMetadata(): void
    {
        $metadata = new CacheMetadata(
            expiresAt: 120.0,
            tags: ['tag'],
            visibility: Visibility::Private,
            reasons: ['reason'],
        );

        $cached = (new CacheEntryConverter())->toCached(new CacheEntry('value', $metadata, retainedUntil: 150.0));

        self::assertSame('value', $cached->value());
        self::assertSame($metadata, $cached->metadata);
    }

    public function testToCachedKeepsAnExpiredExpirationExpired(): void
    {
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 105.0), retainedUntil: 150.0);

        $cached = (new CacheEntryConverter())->toCached($entry);

        self::assertSame(105.0, $cached->metadata->expiresAt);
        self::assertFalse($cached->metadata->isStorable(110.0));
    }

    public function testToEntryConvertsAStorablePublicValue(): void
    {
        $cached = Cached::of('value', new CacheMetadata(expiresAt: 120.0));

        $entry = (new CacheEntryConverter())->toEntry($cached, 100.0, 150.0);

        self::assertNotNull($entry);
        self::assertSame('value', $entry->value());
        self::assertSame(120.0, $entry->expiresAt);
        self::assertSame(150.0, $entry->retainedUntil);
    }

    public function testToEntryDoesNotConvertAnUnstorableValue(): void
    {
        $converter = new CacheEntryConverter();

        self::assertNull($converter->toEntry(Cached::of('value', CacheMetadata::uncacheable('reason')), 100.0));
        self::assertNull($converter->toEntry(Cached::of('value', new CacheMetadata(expiresAt: 90.0)), 100.0));
        self::assertNull($converter->toEntry(Cached::of('value'), 100.0));
    }
}
