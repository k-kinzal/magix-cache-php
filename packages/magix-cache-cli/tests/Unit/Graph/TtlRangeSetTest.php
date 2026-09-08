<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\TtlInterval;
use Magix\Cache\Cli\Graph\TtlRangeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlRangeSet::class)]
#[UsesClass(TtlInterval::class)]
final class TtlRangeSetTest extends TestCase
{
    public function testBoundsNormalizeDuplicatesAndOverlapsWithoutFillingGaps(): void
    {
        $ranges = new TtlRangeSet(new TtlInterval(700, 900), new TtlInterval(30, 30), new TtlInterval(600, 800), new TtlInterval(30, 30));

        self::assertSame([['min' => 30, 'max' => 30], ['min' => 600, 'max' => 900]], $ranges->bounds());
        self::assertSame($ranges->bounds(), (new TtlRangeSet(...$ranges->intervals))->bounds());
    }

    public function testMeetDistributesMinimumOverAlternatives(): void
    {
        $ranges = new TtlRangeSet(new TtlInterval(30, 30), new TtlInterval(600, 900));
        $other = new TtlRangeSet(new TtlInterval(60, 60), new TtlInterval(700, 800));

        self::assertSame('30/60/600-800s', $ranges->meet($other)->label());
        self::assertSame($ranges->meet($other)->bounds(), $other->meet($ranges)->bounds());
        self::assertSame($ranges->bounds(), $ranges->meet($ranges)->bounds());
    }

    #[DataProvider('providerConcreteMinima')]
    public function testMeetMatchesEveryConcreteMinimumAcrossSmallDomains(TtlRangeSet $first, TtlRangeSet $second, int $candidate, bool $expected): void
    {
        $matches = array_filter($first->meet($second)->intervals, static fn (TtlInterval $interval): bool =>
            $interval->min !== null && $interval->max !== null && $interval->min <= $candidate && $candidate <= $interval->max);

        self::assertSame($expected, $matches !== []);
    }

    /**
     * @return iterable<string, array{TtlRangeSet, TtlRangeSet, int, bool}>
     */
    public static function providerConcreteMinima(): iterable
    {
        $sets = [
            [new TtlRangeSet(new TtlInterval(0, 0), new TtlInterval(4, 6)), [0, 4, 5, 6]],
            [new TtlRangeSet(new TtlInterval(1, 3), new TtlInterval(7, 9)), [1, 2, 3, 7, 8, 9]],
            [new TtlRangeSet(new TtlInterval(2, 2), new TtlInterval(8, 8)), [2, 8]],
        ];

        foreach ($sets as $i => [$first, $firstValues]) {
            foreach ($sets as $j => [$second, $secondValues]) {
                $expected = [];

                foreach ($firstValues as $x) {
                    foreach ($secondValues as $y) {
                        $expected[] = min($x, $y);
                    }
                }

                foreach (range(0, 9) as $candidate) {
                    yield $i.':'.$j.':'.$candidate => [$first, $second, $candidate, in_array($candidate, $expected, true)];
                }
            }
        }
    }

    public function testMeetLosesGapsWhenAnUnknownDependencyCanFillThem(): void
    {
        $ranges = new TtlRangeSet(new TtlInterval(30, 30), new TtlInterval(600, 900));

        self::assertSame('≤900s', $ranges->meet(new TtlRangeSet(new TtlInterval()))->label());
        self::assertSame('30/600-900s', $ranges->meet(new TtlRangeSet(new TtlInterval(1000)))->label());
    }

    public function testLowerBoundRequiresEveryAlternativeToHaveOne(): void
    {
        self::assertSame(30, (new TtlRangeSet(new TtlInterval(600, 900), new TtlInterval(30, 30)))->lowerBound());
        self::assertNull((new TtlRangeSet(new TtlInterval(null, 30), new TtlInterval(600, 900)))->lowerBound());
    }

    public function testUpperBoundRequiresEveryAlternativeToHaveOne(): void
    {
        self::assertSame(900, (new TtlRangeSet(new TtlInterval(600, 900), new TtlInterval(30, 30)))->upperBound());
        self::assertNull((new TtlRangeSet(new TtlInterval(600), new TtlInterval(30, 30)))->upperBound());
    }

    public function testLabelAppendsSecondsOnceAndKeepsUndeterminedBounds(): void
    {
        self::assertSame('30/600-900s', (new TtlRangeSet(new TtlInterval(30, 30), new TtlInterval(600, 900)))->label());
        self::assertSame('30/600-?s', (new TtlRangeSet(new TtlInterval(30, 30), new TtlInterval(600)))->label());
    }
}
