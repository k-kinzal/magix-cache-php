<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\ExpirationEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpirationEstimate::class)]
final class ExpirationEstimateTest extends TestCase
{
    public function testLabelKeepsTimeZonePrecisionAndUnknownFields(): void
    {
        self::assertSame('daily 12:00 UTC', (new ExpirationEstimate('12:00'))->label());
        self::assertSame('daily 12:00-12:15 Asia/Tokyo', (new ExpirationEstimate('12:00', '12:15', 'Asia/Tokyo', true))->label());
        self::assertSame('daily 23:59:59-00:00 (+1 day) UTC', (new ExpirationEstimate('23:59:59', '00:00', window: true))->label());
        self::assertSame('daily ?-? ?', (new ExpirationEstimate(null, null, null, true))->label());
    }

    public function testCrossesMidnightUsesLocalClockOrderAndKeepsUncertainty(): void
    {
        self::assertFalse((new ExpirationEstimate('12:00', '12:00:00', window: true))->crossesMidnight());
        self::assertFalse((new ExpirationEstimate('12:00'))->crossesMidnight());
        self::assertTrue((new ExpirationEstimate('23:55', '00:15', window: true))->crossesMidnight());
        self::assertNull((new ExpirationEstimate(null, '00:15', window: true))->crossesMidnight());
    }

    public function testDescribeKeepsSimultaneousWindowsWithoutIntersectingThem(): void
    {
        $noon = new ExpirationEstimate('12:00', '12:15', 'Asia/Tokyo', true);
        $morning = new ExpirationEstimate('09:00', timezone: 'America/New_York');

        self::assertSame('earliest of (daily 12:00-12:15 Asia/Tokyo; daily 09:00 America/New_York)', ExpirationEstimate::describe([$noon, $morning]));
        self::assertSame($noon->label(), ExpirationEstimate::describe([$noon, $noon]));
        self::assertSame('', ExpirationEstimate::describe([]));
    }

    public function testJsonSerializeDoesNotConvertClockTimesToSeconds(): void
    {
        self::assertSame([
            'at' => '12:00', 'until' => '12:15', 'timezone' => 'Asia/Tokyo',
            'window' => true, 'crossesMidnight' => false, 'recurrence' => 'daily',
        ], (new ExpirationEstimate('12:00', '12:15', 'Asia/Tokyo', true))->jsonSerialize());
    }
}
