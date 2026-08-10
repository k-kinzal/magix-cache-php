<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use LogicException;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginFetchResult::class)]
#[UsesClass(CacheEntry::class)]
#[UsesClass(OriginFetchOutcome::class)]
#[UsesClass(Cached::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Runtime\Metadata\CacheTokenSet::class)]
final class OriginFetchResultTest extends TestCase
{
    public function testOriginValueReturnsOriginResult(): void
    {
        $origin = Cached::of('value');
        $result = new OriginFetchResult($origin);

        self::assertSame(OriginFetchOutcome::Origin, $result->outcome);
        self::assertSame($origin, $result->originValue());
    }

    public function testOriginValueRejectsStaleResult(): void
    {
        $this->expectException(LogicException::class);

        (new OriginFetchResult(new CacheEntry('stale', 100.0)))->originValue();
    }

    public function testStaleEntryReturnsRetainedEntry(): void
    {
        $entry = new CacheEntry('stale', 100.0);
        $result = new OriginFetchResult($entry);

        self::assertSame(OriginFetchOutcome::Stale, $result->outcome);
        self::assertSame($entry, $result->staleEntry());
    }

    public function testStaleEntryRejectsOriginResult(): void
    {
        $this->expectException(LogicException::class);

        (new OriginFetchResult(Cached::of('value')))->staleEntry();
    }
}
