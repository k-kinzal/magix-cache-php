<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlEstimate::class)]
final class TtlEstimateTest extends TestCase
{
    public function testKnownCarriesTheSecondsAndTheirDerivation(): void
    {
        $estimate = TtlEstimate::known(20, 'declared 120s, capped by ProductQuery::execute');

        self::assertSame(TtlEstimateState::Known, $estimate->state);
        self::assertSame(20, $estimate->seconds);
        self::assertNull($estimate->upperBound);
        self::assertSame('declared 120s, capped by ProductQuery::execute', $estimate->reason);
    }

    public function testUnconstrainedCarriesNothing(): void
    {
        $estimate = TtlEstimate::unconstrained();

        self::assertSame(TtlEstimateState::Unconstrained, $estimate->state);
        self::assertNull($estimate->seconds);
        self::assertNull($estimate->upperBound);
        self::assertNull($estimate->reason);
    }

    public function testUnknownCarriesAnUpperBoundAndACondition(): void
    {
        $estimate = TtlEstimate::unknown(30, 'requires a finite upstream expiration at runtime');

        self::assertSame(TtlEstimateState::Unknown, $estimate->state);
        self::assertNull($estimate->seconds);
        self::assertSame(30, $estimate->upperBound);
        self::assertSame('requires a finite upstream expiration at runtime', $estimate->reason);
    }

    public function testInvalidCarriesItsProblem(): void
    {
        $estimate = TtlEstimate::invalid('Ttl::Auto has nothing to inherit');

        self::assertSame(TtlEstimateState::Invalid, $estimate->state);
        self::assertSame('Ttl::Auto has nothing to inherit', $estimate->reason);
    }

    public function testMeetKeepsTheEarlierOfTwoKnownLifetimes(): void
    {
        $earlier = TtlEstimate::known(10);
        $later = TtlEstimate::known(20);

        self::assertSame($earlier, $earlier->meet($later));
        self::assertSame($earlier, $later->meet($earlier));
    }

    public function testMeetTreatsUnconstrainedAsTheIdentity(): void
    {
        $known = TtlEstimate::known(10);

        self::assertSame($known, TtlEstimate::unconstrained()->meet($known));
        self::assertSame($known, $known->meet(TtlEstimate::unconstrained()));
        self::assertSame(
            TtlEstimateState::Unconstrained,
            TtlEstimate::unconstrained()->meet(TtlEstimate::unconstrained())->state,
        );
    }

    public function testMeetAbsorbsAKnownLifetimeIntoAnUnknownUpperBound(): void
    {
        $met = TtlEstimate::known(10)->meet(TtlEstimate::unknown(30, 'a resolver decides'));

        self::assertSame(TtlEstimateState::Unknown, $met->state);
        self::assertSame(10, $met->upperBound);
        self::assertSame('a resolver decides', $met->reason);
    }

    public function testMeetPropagatesInvalidOverEverythingElse(): void
    {
        $invalid = TtlEstimate::invalid('broken');

        self::assertSame($invalid, $invalid->meet(TtlEstimate::known(10)));
        self::assertSame($invalid, TtlEstimate::known(10)->meet($invalid));
        self::assertSame($invalid, TtlEstimate::unknown()->meet($invalid));
    }

    public function testMeetKeepsTheProvableBoundsOfARange(): void
    {
        $met = TtlEstimate::unknown(60, null, 30)->meet(TtlEstimate::unknown(null, null, 30));

        self::assertSame(TtlEstimateState::Unknown, $met->state);
        self::assertSame(30, $met->lowerBound);
        self::assertSame(60, $met->upperBound);
        self::assertSame('30-60s', $met->label());
    }

    public function testMeetCollapsesAPinnedRangeWithoutAConditionIntoKnown(): void
    {
        $met = TtlEstimate::unknown(60, null, 60)->meet(TtlEstimate::unknown(null, null, 60));

        self::assertSame(TtlEstimateState::Known, $met->state);
        self::assertSame(60, $met->seconds);
    }

    public function testMeetKeepsAPinnedRangeUnknownUnderACondition(): void
    {
        $met = TtlEstimate::unknown(60, 'requires a finite upstream expiration at runtime', 60)
            ->meet(TtlEstimate::unknown(null, null, 60));

        self::assertSame(TtlEstimateState::Unknown, $met->state);
        self::assertSame(60, $met->lowerBound);
        self::assertSame(60, $met->upperBound);
    }

    public function testMeetDropsTheLowerBoundWhenOneSideGuaranteesNone(): void
    {
        $met = TtlEstimate::unknown(60, null, 30)->meet(TtlEstimate::unknown(45));

        self::assertNull($met->lowerBound);
        self::assertSame(45, $met->upperBound);
        self::assertSame('≤45s', $met->label());
    }

    public function testBoundReturnsTheTightestGuaranteedUpperBound(): void
    {
        self::assertSame(10, TtlEstimate::known(10)->bound(TtlEstimate::unknown(30)));
        self::assertSame(5, TtlEstimate::unknown(5)->bound(TtlEstimate::unknown(30)));
        self::assertSame(30, TtlEstimate::unknown()->bound(TtlEstimate::unknown(30)));
        self::assertNull(TtlEstimate::unknown()->bound(TtlEstimate::unknown()));
    }

    public function testFloorSurvivesOnlyWhenBothSidesGuaranteeOne(): void
    {
        self::assertSame(10, TtlEstimate::known(10)->floor(TtlEstimate::unknown(60, null, 30)));
        self::assertSame(30, TtlEstimate::unknown(60, null, 45)->floor(TtlEstimate::unknown(null, null, 30)));
        self::assertNull(TtlEstimate::unknown(60, null, 30)->floor(TtlEstimate::unknown(45)));
    }

    public function testConditionKeepsTheUnknownSideRequirement(): void
    {
        $unknown = TtlEstimate::unknown(null, 'requires a finite upstream expiration at runtime');

        self::assertSame('requires a finite upstream expiration at runtime', $unknown->condition(TtlEstimate::known(10)));
        self::assertSame('requires a finite upstream expiration at runtime', TtlEstimate::known(10)->condition($unknown));
        self::assertNull(TtlEstimate::known(10, 'inherited')->condition(TtlEstimate::known(20)));
    }

    public function testEqualsComparesEveryField(): void
    {
        self::assertTrue(TtlEstimate::known(10)->equals(TtlEstimate::known(10)));
        self::assertFalse(TtlEstimate::known(10)->equals(TtlEstimate::known(20)));
        self::assertFalse(TtlEstimate::unknown(10)->equals(TtlEstimate::known(10)));
        self::assertFalse(TtlEstimate::unknown(10, 'a')->equals(TtlEstimate::unknown(10)));
    }

    public function testLabelRendersEveryStateHonestly(): void
    {
        self::assertSame('30s', TtlEstimate::known(30)->label());
        self::assertSame('unconstrained', TtlEstimate::unconstrained()->label());
        self::assertSame('unknown', TtlEstimate::unknown()->label());
        self::assertSame('≤30s', TtlEstimate::unknown(30)->label());
        self::assertSame('30-60s', TtlEstimate::unknown(60, null, 30)->label());
        self::assertSame('30-?s', TtlEstimate::unknown(null, null, 30)->label());
        self::assertSame('invalid', TtlEstimate::invalid('broken')->label());
    }

    public function testJsonSerializeEncodesTheStateAndEveryField(): void
    {
        self::assertSame(
            ['state' => 'unknown', 'seconds' => null, 'lowerBound' => null, 'upperBound' => 30, 'reason' => 'conditional'],
            TtlEstimate::unknown(30, 'conditional')->jsonSerialize(),
        );
        self::assertSame(
            ['state' => 'known', 'seconds' => 20, 'lowerBound' => null, 'upperBound' => null, 'reason' => null],
            TtlEstimate::known(20)->jsonSerialize(),
        );
    }
}
