<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheSet::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class CacheSetTest extends TestCase
{
    public function testWithKeyPreservesEntryAndChangesKey(): void
    {
        $entry = new CacheEntry('value', 120.0);
        $changed = (new CacheSet('original', $entry))->withKey('changed');

        self::assertSame('changed', $changed->key);
        self::assertSame($entry, $changed->entry());
    }

    public function testWithEntryPreservesKeyAndChangesEntry(): void
    {
        $entry = new CacheEntry('value', 120.0);
        $changed = (new CacheSet('key', new CacheEntry('old', 110.0)))->withEntry($entry);

        self::assertSame('key', $changed->key);
        self::assertSame($entry, $changed->entry());
    }

    public function testEntryReturnsCompleteEntry(): void
    {
        $entry = new CacheEntry('value', 120.0);

        self::assertSame($entry, (new CacheSet('key', $entry))->entry());
    }
}
