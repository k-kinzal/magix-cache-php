<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\CacheEntryConverter;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEntryConverter::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
#[UsesClass(Visibility::class)]
final class CacheEntryConverterTest extends TestCase
{
    public function testToCachedConvertsAnInternalEntryToThePublicRepresentation(): void
    {
        $converter = new CacheEntryConverter();
        $entry = new CacheEntry(
            value: 'value',
            expiresAt: 120.0,
            tags: ['tag'],
            visibility: Visibility::Private,
            reasons: ['reason'],
        );

        self::assertEquals(
            Cached::of('value', new CacheMetadata(
                expiresAt: 120.0,
                tags: ['tag'],
                visibility: Visibility::Private,
                reasons: ['reason'],
            )),
            $converter->toCached($entry),
        );
    }

    public function testToEntryConvertsAStorablePublicValue(): void
    {
        $cached = Cached::of('value', new CacheMetadata(expiresAt: 120.0));

        $entry = (new CacheEntryConverter())->toEntry($cached, 100.0);

        self::assertNotNull($entry);
        self::assertSame('value', $entry->value());
    }

    public function testToEntryDoesNotConvertAnUnstorableValue(): void
    {
        $cached = Cached::of('value', CacheMetadata::uncacheable('reason'));

        self::assertNull((new CacheEntryConverter())->toEntry($cached, 100.0));
    }
}
