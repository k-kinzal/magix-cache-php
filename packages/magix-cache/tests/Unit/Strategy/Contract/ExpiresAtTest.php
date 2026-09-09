<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\ExpiresAt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpiresAt::class)]
#[UsesClass(ConstructorArg::class)]
final class ExpiresAtTest extends TestCase
{
    public function testDeclaresSingleTimesAndDistributedWindows(): void
    {
        $single = new ExpiresAt('12:00');
        $window = new ExpiresAt('12:00', until: '12:15', timezone: 'Asia/Tokyo');
        $overnight = new ExpiresAt('23:55:30', until: '00:10:15');
        $reference = new ConstructorArg('cutoff');
        $bound = new ExpiresAt($reference, new ConstructorArg('end'), new ConstructorArg('zone'));

        self::assertSame('UTC', $single->timezone);
        self::assertNull($single->until);
        self::assertSame('12:15', $window->until);
        self::assertSame('Asia/Tokyo', $window->timezone);
        self::assertSame('00:10:15', $overnight->until);
        self::assertSame($reference, $bound->at);
    }

    public function testValidTimeRecognizesMinuteAndSecondPrecision(): void
    {
        self::assertTrue(ExpiresAt::validTime('00:00'));
        self::assertTrue(ExpiresAt::validTime('23:59:59'));
    }

    public function testValidTimezoneIncludesUtcAndDaylightSavingZones(): void
    {
        self::assertTrue(ExpiresAt::validTimezone('UTC'));
        self::assertTrue(ExpiresAt::validTimezone('Asia/Tokyo'));
        self::assertTrue(ExpiresAt::validTimezone('America/New_York'));
    }
}
