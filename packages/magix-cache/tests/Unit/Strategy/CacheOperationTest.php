<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheOperation::class)]
#[UsesClass(Cached::class)]
#[UsesClass(CacheMetadata::class)]
#[UsesClass(\Magix\Cache\Metadata\CacheTokenSet::class)]
final class CacheOperationTest extends TestCase
{
    public function testKeyReturnsTheResolvedStorageKey(): void
    {
        self::assertSame('key', (new CacheOperation('key', static fn (): float => 100.0))->key());
    }

    public function testNowReadsTheClockOnEveryCall(): void
    {
        $now = 100.0;
        $operation = new CacheOperation('key', static function () use (&$now): float {
            return $now;
        });

        self::assertSame(100.0, $operation->now());

        $now = 101.0;
        self::assertSame(101.0, $operation->now());
    }

}
