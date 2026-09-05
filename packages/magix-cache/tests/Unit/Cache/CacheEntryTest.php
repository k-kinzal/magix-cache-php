<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEntry::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
#[UsesClass(Visibility::class)]
final class CacheEntryTest extends TestCase
{
    public function testValueReturnsInternalValueWithItsMetadata(): void
    {
        $metadata = new CacheMetadata(expiresAt: 120.0, tags: ['product:1']);
        $entry = new CacheEntry('value', $metadata);

        self::assertSame('value', $entry->value());
        self::assertSame($metadata, $entry->metadata);
        self::assertSame(120.0, $entry->expiresAt);
        self::assertSame(120.0, $entry->retainedUntil);
        self::assertSame(CacheEntry::FORMAT_VERSION, $entry->formatVersion);
    }

    public function testWithRetainedUntilPreservesValueAndExpiration(): void
    {
        $entry = new CacheEntry('value', new CacheMetadata(expiresAt: 120.0, tags: ['product:1']));
        $retained = $entry->withRetainedUntil(150.0);

        self::assertSame('value', $retained->value());
        self::assertSame(120.0, $retained->expiresAt);
        self::assertSame(150.0, $retained->retainedUntil);
        self::assertSame(['product:1'], $retained->metadata->tags);
    }
}
