<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectCalculator::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(DependencyConstraint::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(Visibility::class)]
final class EffectCalculatorTest extends TestCase
{
    public function testConstrainSelectsTheEarliestExpirationAndStrictestVisibility(): void
    {
        $constraint = (new EffectCalculator())->constrain([
            new CacheNode(
                new BoundaryDeclaration('App\InventoryQuery', 'execute', 'a.php', 1),
                new CacheEffect(ttl: TtlEstimate::known(60), tags: ['inventory']),
            ),
            new CacheNode(
                new BoundaryDeclaration('App\ViewerQuery', 'execute', 'b.php', 1),
                new CacheEffect(ttl: TtlEstimate::known(30), visibility: Visibility::Private, tags: ['viewer']),
            ),
        ]);

        self::assertSame(30, $constraint->ttl->seconds);
        self::assertSame('ViewerQuery::execute', $constraint->ttlSource);
        self::assertSame(Visibility::Private, $constraint->visibility);
        self::assertSame('ViewerQuery::execute', $constraint->visibilitySource);
        self::assertSame(['inventory', 'viewer'], $constraint->tags);
    }

    public function testConstrainStaysUnconstrainedWithoutConstrainedChildren(): void
    {
        $calculator = new EffectCalculator();
        $empty = $calculator->constrain([]);
        $unconstrained = $calculator->constrain([
            new CacheNode(
                new BoundaryDeclaration('App\StaticQuery', 'execute', 'a.php', 1),
                new CacheEffect(ttl: TtlEstimate::unconstrained()),
            ),
        ]);

        self::assertSame(TtlEstimateState::Unconstrained, $empty->ttl->state);
        self::assertNull($empty->ttlSource);
        self::assertSame(TtlEstimateState::Unconstrained, $unconstrained->ttl->state);
    }

    public function testConstrainTurnsAKnownLifetimeUnknownNextToAnUnknownChild(): void
    {
        $constraint = (new EffectCalculator())->constrain([
            new CacheNode(
                new BoundaryDeclaration('App\ProductQuery', 'execute', 'a.php', 1),
                new CacheEffect(ttl: TtlEstimate::known(20)),
            ),
            new CacheNode(
                new BoundaryDeclaration('App\RateQuery', 'execute', 'b.php', 1),
                new CacheEffect(ttl: TtlEstimate::unknown(60, 'a resolver decides')),
            ),
        ]);

        self::assertSame(TtlEstimateState::Unknown, $constraint->ttl->state);
        self::assertSame(20, $constraint->ttl->upperBound);
        self::assertSame('RateQuery::execute', $constraint->ttlSource);
    }

    public function testCalculateCapsAFixedTtlByItsDependencies(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120, tags: ['page']),
        );

        $effect = (new EffectCalculator())->calculate(
            $boundary,
            new DependencyConstraint(TtlEstimate::known(20), 'ProductQuery::execute', tags: ['product']),
        );

        self::assertSame(20, $effect->ttl->seconds);
        self::assertSame('declared 120s, capped by ProductQuery::execute', $effect->ttl->reason);
        self::assertSame(['page', 'product'], $effect->tags);
        self::assertTrue($effect->storable);
    }

    public function testCalculateReportsABoundaryWithoutAnyPolicy(): void
    {
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint());

        self::assertSame(TtlEstimateState::Invalid, $effect->ttl->state);
        self::assertFalse($effect->storable);
        self::assertCount(1, $effect->problems);
    }

    public function testCalculateRestrictsVisibilityThroughScopedParameters(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\ViewerQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 30),
            parameters: [new KeyParameter('viewerId', scope: Visibility::Private)],
        );

        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint());

        self::assertSame(Visibility::Private, $effect->visibility);
        self::assertSame('restricted by a scoped parameter', $effect->visibilityReason);
    }

    public function testCalculateDoesNotStoreAnUnknownLifetime(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\RateQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 30),
            hasDynamicTtl: true,
        );

        $effect = (new EffectCalculator())->calculate($boundary, new DependencyConstraint());

        self::assertSame(TtlEstimateState::Unknown, $effect->ttl->state);
        self::assertSame(30, $effect->ttl->upperBound);
        self::assertFalse($effect->storable);
        self::assertSame([], $effect->problems);
    }

    public function testLifetimeKeepsAFixedTtlUnknownUnderAnUnknownUpstream(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $estimate = $calculator->lifetime(
            $boundary,
            new PolicyDeclaration(PolicySource::MethodAttribute, 30),
            new DependencyConstraint(TtlEstimate::unknown(), 'RateQuery::execute'),
        );

        self::assertSame(TtlEstimateState::Unknown, $estimate->state);
        self::assertSame(30, $estimate->upperBound);
    }

    public function testLifetimeKeepsAnUnreadableTtlUnknown(): void
    {
        $estimate = (new EffectCalculator())->lifetime(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1),
            new PolicyDeclaration(PolicySource::MethodAttribute, null),
            new DependencyConstraint(),
        );

        self::assertSame(TtlEstimateState::Unknown, $estimate->state);
        self::assertSame('the declared ttl cannot be read statically', $estimate->reason);
    }

    public function testLifetimePropagatesAnInvalidUpstream(): void
    {
        $invalid = TtlEstimate::invalid('a dependency cannot work as written');

        $estimate = (new EffectCalculator())->lifetime(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1),
            new PolicyDeclaration(PolicySource::MethodAttribute, 30),
            new DependencyConstraint($invalid),
        );

        self::assertSame($invalid, $estimate);
    }

    public function testFixedIsCappedByAKnownUpstreamAndStandsAlone(): void
    {
        $calculator = new EffectCalculator();

        $capped = $calculator->fixed(20, TtlEstimate::known(10), 'ProductQuery::execute');
        $reached = $calculator->fixed(20, TtlEstimate::known(60), 'ProductQuery::execute');
        $alone = $calculator->fixed(20, TtlEstimate::unconstrained(), 'a dependency');

        self::assertSame(10, $capped->seconds);
        self::assertSame('declared 20s, capped by ProductQuery::execute', $capped->reason);
        self::assertSame(20, $reached->seconds);
        self::assertNull($reached->reason);
        self::assertSame(20, $alone->seconds);
    }

    public function testFixedKeepsOnlyAnUpperBoundUnderAnUnknownUpstream(): void
    {
        $calculator = new EffectCalculator();

        $bounded = $calculator->fixed(30, TtlEstimate::unknown(), 'a dependency');
        $tighter = $calculator->fixed(30, TtlEstimate::unknown(10), 'a dependency');

        self::assertSame(TtlEstimateState::Unknown, $bounded->state);
        self::assertSame(30, $bounded->upperBound);
        self::assertSame(TtlEstimateState::Unknown, $tighter->state);
        self::assertSame(10, $tighter->upperBound);
    }

    public function testDerivedInheritsAndCapsAKnownUpstream(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $inherited = $calculator->derived(Ttl::Auto, null, $boundary, TtlEstimate::known(45), 'FeedQuery::execute');
        $capped = $calculator->derived(Ttl::FromUpstream, 10, $boundary, TtlEstimate::known(45), 'FeedQuery::execute');

        self::assertSame(45, $inherited->seconds);
        self::assertSame('inherited from FeedQuery::execute', $inherited->reason);
        self::assertSame(10, $capped->seconds);
        self::assertSame('upstream expiration capped at 10s', $capped->reason);
    }

    public function testDerivedKeepsAnUnknownUpstreamConditionalInsteadOfAssumingTheCap(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $fromUpstream = $calculator->derived(Ttl::FromUpstream, 30, $boundary, TtlEstimate::unknown(), 'RateQuery::execute');
        $automatic = $calculator->derived(Ttl::Auto, null, $boundary, TtlEstimate::unknown(10), 'RateQuery::execute');

        self::assertSame(TtlEstimateState::Unknown, $fromUpstream->state);
        self::assertNull($fromUpstream->seconds);
        self::assertSame(30, $fromUpstream->upperBound);
        self::assertSame('requires a finite upstream expiration at runtime', $fromUpstream->reason);
        self::assertSame(TtlEstimateState::Unknown, $automatic->state);
        self::assertSame(10, $automatic->upperBound);
    }

    public function testDerivedRejectsAConfirmedUnconstrainedUpstream(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        $automatic = $calculator->derived(Ttl::Auto, null, $boundary, TtlEstimate::unconstrained(), 'a dependency');
        $fromUpstream = $calculator->derived(Ttl::FromUpstream, 30, $boundary, TtlEstimate::unconstrained(), 'a dependency');
        $unbounded = $calculator->derived(Ttl::FromUpstream, null, $boundary, TtlEstimate::known(10), 'a dependency');

        self::assertSame(TtlEstimateState::Invalid, $automatic->state);
        self::assertSame(TtlEstimateState::Invalid, $fromUpstream->state);
        self::assertSame(TtlEstimateState::Invalid, $unbounded->state);
    }

    public function testDerivedAcceptsABoundaryThatSuppliesItsOwnExpiration(): void
    {
        $calculator = new EffectCalculator();
        $supplying = new BoundaryDeclaration('App\UpstreamQuery', 'execute', 'a.php', 1, suppliesMetadata: true);
        $resolving = new BoundaryDeclaration('App\RateQuery', 'execute', 'b.php', 1, hasDynamicTtl: true);

        $supplied = $calculator->derived(Ttl::Auto, null, $supplying, TtlEstimate::unconstrained(), 'a dependency');
        $resolved = $calculator->derived(Ttl::FromUpstream, 30, $resolving, TtlEstimate::unconstrained(), 'a dependency');

        self::assertSame(TtlEstimateState::Unknown, $supplied->state);
        self::assertNull($supplied->upperBound);
        self::assertSame(TtlEstimateState::Unknown, $resolved->state);
        self::assertSame(30, $resolved->upperBound);
    }

    public function testTagsAreDeduplicatedAndSorted(): void
    {
        self::assertSame(['a', 'b'], (new EffectCalculator())->tags(['b', 'a', 'b']));
    }
}
