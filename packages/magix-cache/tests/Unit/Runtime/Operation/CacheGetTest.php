<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime\Operation;

use Magix\Cache\Runtime\Operation\CacheGet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(CacheGet::class)]
final class CacheGetTest extends TestCase
{
    public function testNowUsesRuntimeClock(): void
    {
        self::assertSame(100.5, (new CacheGet('key', new MutableClock(100.5)))->now());
    }

    public function testWithKeyPreservesClockAndChangesKey(): void
    {
        $clock = new MutableClock(100.0);
        $changed = (new CacheGet('original', $clock))->withKey('changed');
        $clock->advance(5.0);

        self::assertSame('changed', $changed->key);
        self::assertSame(105.0, $changed->now());
    }
}
