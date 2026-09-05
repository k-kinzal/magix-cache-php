<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Magix\Cache\Runtime\UnixClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\MutableClock;

#[CoversClass(UnixClock::class)]
final class UnixClockTest extends TestCase
{
    public function testNowReturnsSubSecondUnixTime(): void
    {
        self::assertSame(100.5, (new UnixClock(new MutableClock(100.5)))->now());
    }
}
