<?php

declare(strict_types=1);

namespace Tests\Unit\Clock;

use Magix\Cache\Clock\SystemClock;

use function microtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testNowReturnsCurrentUnixTime(): void
    {
        $before = microtime(true);
        $actual = (float) (new SystemClock())->now()->format('U.u');
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $actual);
        self::assertLessThanOrEqual($after, $actual);
    }
}
