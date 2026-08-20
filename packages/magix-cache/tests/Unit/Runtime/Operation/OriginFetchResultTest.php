<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\OriginFetchProvenance;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginFetchResult::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(OriginFetchProvenance::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class OriginFetchResultTest extends TestCase
{
    public function testOriginValueReturnsOriginResult(): void
    {
        $origin = Cached::of('value');
        $result = new OriginFetchResult($origin);

        self::assertSame(OriginFetchProvenance::Origin, $result->provenance);
        self::assertSame($origin, $result->originValue());
    }

    public function testStaleEntryReturnsRetainedEntry(): void
    {
        $entry = new CacheEntry('stale', 100.0);
        $result = new OriginFetchResult($entry);

        self::assertSame(OriginFetchProvenance::Stale, $result->provenance);
        self::assertSame($entry, $result->staleEntry());
    }
}
