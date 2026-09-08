<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\TtlInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlInterval::class)]
final class TtlIntervalTest extends TestCase
{
    public function testMeetComputesMinimumImagesInsteadOfIntersection(): void
    {
        self::assertSame(['min' => 30, 'max' => 30], (new TtlInterval(30, 30))->meet(new TtlInterval(600, 900))->bounds());
        self::assertSame(['min' => 600, 'max' => 700], (new TtlInterval(600, 900))->meet(new TtlInterval(700, 700))->bounds());
        self::assertSame(['min' => null, 'max' => 900], (new TtlInterval(600, 900))->meet(new TtlInterval())->bounds());
    }

    public function testCompareOrdersMissingLowerBoundsBeforeKnownBounds(): void
    {
        self::assertLessThan(0, (new TtlInterval())->compare(new TtlInterval(0, 30)));
        self::assertGreaterThan(0, (new TtlInterval(600, 900))->compare(new TtlInterval(30, 30)));
        self::assertSame(0, (new TtlInterval(30, 60))->compare(new TtlInterval(30, 90)));
    }

    public function testOverlapsKeepsGapsIncludingAdjacentSingletons(): void
    {
        self::assertFalse((new TtlInterval(30, 30))->overlaps(new TtlInterval(31, 31)));
        self::assertTrue((new TtlInterval(30, 60))->overlaps(new TtlInterval(60, 90)));
        self::assertTrue((new TtlInterval(30))->overlaps(new TtlInterval(600, 900)));
    }

    public function testCoverPreservesMissingBoundsAcrossOverlappingIntervals(): void
    {
        self::assertSame(['min' => 30, 'max' => 90], (new TtlInterval(30, 60))->cover(new TtlInterval(60, 90))->bounds());
        self::assertSame(['min' => null, 'max' => null], (new TtlInterval(null, 60))->cover(new TtlInterval(30))->bounds());
    }

    public function testLabelDistinguishesPointsRangesAndUndeterminedBounds(): void
    {
        self::assertSame('30', (new TtlInterval(30, 30))->label());
        self::assertSame('600-900', (new TtlInterval(600, 900))->label());
        self::assertSame('600-?', (new TtlInterval(600))->label());
        self::assertSame('≤900', (new TtlInterval(null, 900))->label());
        self::assertSame('?', (new TtlInterval())->label());
    }

    public function testBoundsExposeOnlyWhatIsProven(): void
    {
        self::assertSame(['min' => null, 'max' => null], (new TtlInterval())->bounds());
        self::assertSame(['min' => 600, 'max' => 900], (new TtlInterval(600, 900))->bounds());
    }
}
