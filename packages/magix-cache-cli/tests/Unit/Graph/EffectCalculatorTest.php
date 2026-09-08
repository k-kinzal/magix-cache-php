<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Console\CatalogLoader;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectCalculator::class)]
#[UsesNamespace('Magix\Cache')]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(DependencyConstraint::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(StrategyEffect::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(Visibility::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ExpirationEstimate::class)]
final class EffectCalculatorTest extends TestCase
{
    public function testComposePreservesUnknownConstraintsWithoutRequiringAPolicy(): void
    {
        $root = new BoundaryDeclaration('App\ProductController', 'show', 'a.php', 1, isCacheBoundary: false);
        $ttl = TtlEstimate::unknown(20, 'runtime ttl', 0);
        $effect = (new EffectCalculator())->calculate($root, new DependencyConstraint(
            ttl: $ttl,
            visibility: Visibility::Private,
            visibilitySource: 'ViewerQuery::execute',
            tags: ['product', 'viewer'],
            visibilityUnknown: true,
            tagsUnknown: true,
        ));

        self::assertSame($ttl, $effect->ttl);
        self::assertSame(Visibility::Private, $effect->visibility);
        self::assertSame('restricted by ViewerQuery::execute', $effect->visibilityReason);
        self::assertSame(['product', 'viewer'], $effect->tags);
        self::assertTrue($effect->visibilityUnknown);
        self::assertTrue($effect->tagsUnknown);
        self::assertFalse($effect->storable);
        self::assertSame([], $effect->problems);
    }

    public function testAnUncachedRootPreservesNoStoreAndInvalidDependencies(): void
    {
        $root = new BoundaryDeclaration('App\ProductController', 'show', 'a.php', 1, isCacheBoundary: false);
        $calculator = new EffectCalculator();
        $noStore = $calculator->calculate($root, new DependencyConstraint(TtlEstimate::known(20), visibility: Visibility::NoStore));
        $invalid = $calculator->calculate($root, new DependencyConstraint(TtlEstimate::invalid('a dependency is invalid')));

        self::assertSame(Visibility::NoStore, $noStore->visibility);
        self::assertFalse($noStore->storable);
        self::assertSame(TtlEstimateState::Invalid, $invalid->ttl->state);
        self::assertSame(['a dependency is invalid'], $invalid->problems);
    }

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

    public function testApplyPolicyCapsAFixedTtlByItsDependencies(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120, tags: ['page']),
        );

        $effect = (new EffectCalculator())->applyPolicy(
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

    public function testLifetimeLetsAStrategyContractSatisfyADerivedPolicy(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\PromotedQuery', 'execute', 'a.php', 1);
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, ttl: Ttl::Auto);
        $strategy = new StrategyEffect('S::create(min: 30)', TtlEstimate::unknown(60, null, 30), addsConstraint: true);

        $estimate = $calculator->lifetime($boundary, $policy, new DependencyConstraint(), $strategy);

        self::assertSame(TtlEstimateState::Unknown, $estimate->state);
        self::assertSame(30, $estimate->lowerBound);
        self::assertSame(60, $estimate->upperBound);
        self::assertNull($estimate->reason);
    }

    public function testLifetimeKeepsCandidateAndEffectiveLifetimesApart(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\SeasonalQuery', 'execute', 'a.php', 1);
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, ttl: Ttl::Auto);
        $strategy = new StrategyEffect('S::create(min: 30)', TtlEstimate::unknown(60, null, 30), addsConstraint: true);
        $constraint = new DependencyConstraint(TtlEstimate::known(20), 'ProductQuery::execute');

        $estimate = $calculator->lifetime($boundary, $policy, $constraint, $strategy);

        self::assertSame(20, $estimate->seconds, 'the upstream expiration shortens the candidate');
        self::assertSame('30-60s', $strategy->ttl->label(), 'the candidate range itself stays untouched');
    }

    public function testLifetimeMarksAnUnknownUpstreamAsAShorteningCondition(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration('App\SeasonalQuery', 'execute', 'a.php', 1);
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute, ttl: Ttl::Auto);
        $strategy = new StrategyEffect('S::create(min: 30)', TtlEstimate::unknown(60, null, 30), addsConstraint: true);
        $constraint = new DependencyConstraint(TtlEstimate::unknown());

        $estimate = $calculator->lifetime($boundary, $policy, $constraint, $strategy);

        self::assertSame('≤60s', $estimate->label());
        self::assertSame('an upstream expiration may shorten the lifetime', $estimate->reason);
    }

    public function testCalculateSurfacesStrategyProblemsOnTheEffect(): void
    {
        $calculator = new EffectCalculator();
        $boundary = new BoundaryDeclaration(
            'App\BrokenQuery',
            'execute',
            'a.php',
            1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, ttl: 30),
        );
        $strategy = new StrategyEffect('S::create()', TtlEstimate::invalid('problem'), problems: ['problem']);

        $effect = $calculator->calculate($boundary, new DependencyConstraint(), $strategy);

        self::assertSame(['problem'], $effect->problems);
        self::assertFalse($effect->storable);
        self::assertSame($strategy, $effect->strategy);
    }

    public function testStorableAcceptsAProvenPositiveLowerBoundWithoutACondition(): void
    {
        $calculator = new EffectCalculator();

        self::assertTrue($calculator->storable(TtlEstimate::known(30), Visibility::Shared, []));
        self::assertTrue($calculator->storable(TtlEstimate::unknown(60, null, 30), Visibility::Shared, []));
        self::assertFalse($calculator->storable(TtlEstimate::unknown(60, 'a condition', 30), Visibility::Shared, []));
        self::assertFalse($calculator->storable(TtlEstimate::unknown(60), Visibility::Shared, []));
        self::assertFalse($calculator->storable(TtlEstimate::known(30), Visibility::NoStore, []));
        self::assertFalse($calculator->storable(TtlEstimate::known(30), Visibility::Shared, ['problem']));
    }

    public function testProblemsMergeTheStrategyProblemsWithoutDuplicates(): void
    {
        $calculator = new EffectCalculator();
        $strategy = new StrategyEffect('S::create()', TtlEstimate::invalid('shared'), problems: ['shared', 'extra']);

        self::assertSame(['shared', 'extra'], $calculator->problems(['shared'], $strategy));
        self::assertSame(['own'], $calculator->problems(['own'], null));
    }

    public function testCalculateParameterTtlSuppliesAutoAndPreservesProvenCaps(): void
    {
        $calculator = new EffectCalculator();
        $parameter = new KeyParameter('ttl', 'int', configuration: new ParameterConfiguration(ttl: true));
        $auto = new BoundaryDeclaration(
            'Query',
            'fetch',
            'a.php',
            1,
            policy: new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: Ttl::Auto),
            parameters: [$parameter],
        );
        $effect = $calculator->calculate($auto, new DependencyConstraint());

        self::assertSame(TtlEstimateState::Unknown, $effect->ttl->state);
        self::assertSame([], $effect->problems);
        self::assertStringContainsString('$ttl', $effect->ttl->reason ?? '');
        $capped = new BoundaryDeclaration(
            'Query',
            'fetch',
            'a.php',
            1,
            policy: new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: 60),
            parameters: [$parameter],
        );
        $bounded = $calculator->calculate($capped, new DependencyConstraint(TtlEstimate::known(20)));

        self::assertSame(TtlEstimateState::Unknown, $bounded->ttl->state);
        self::assertSame(20, $bounded->ttl->upperBound);
        self::assertSame(0, $bounded->ttl->lowerBound);
        self::assertStringContainsString('$ttl', $bounded->ttl->reason ?? '');
    }

    public function testConstrainPropagatesUnknownParameterMetadataToParents(): void
    {
        $calculator = new EffectCalculator();
        $child = new BoundaryDeclaration(
            'Child',
            'fetch',
            'a.php',
            1,
            policy: new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: 60),
            parameters: [
                new KeyParameter('tags', 'array', configuration: new ParameterConfiguration(tags: true)),
                new KeyParameter('visibility', Visibility::class, configuration: new ParameterConfiguration(visibility: true)),
            ],
        );
        $childEffect = $calculator->calculate($child, new DependencyConstraint());
        $constraint = $calculator->constrain([new CacheNode($child, $childEffect)]);
        $parent = new BoundaryDeclaration('ParentQuery', 'fetch', 'b.php', 1, policy: new PolicyDeclaration(source: PolicySource::MethodAttribute, ttl: 30));
        $effect = $calculator->calculate($parent, $constraint);

        self::assertTrue($effect->visibilityUnknown);
        self::assertTrue($effect->tagsUnknown);
        self::assertFalse($effect->storable);
        self::assertSame(30, $effect->ttl->seconds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providerCompositionOperations(): iterable
    {
        yield 'nested cached values' => ['flatten'];
        yield 'typed pair' => ['zip'];
        yield 'pair projection' => ['unzip'];
        yield 'collection' => ['sequence'];
        yield 'mapped collection' => ['traverse'];
    }

    #[DataProvider('providerCompositionOperations')]
    public function testConstrainKeepsDependenciesThroughFunctionalComposition(string $operation): void
    {
        $catalog = (new CatalogLoader(dirname(__DIR__, 5)))
            ->load(['packages/magix-cache-cli/tests/Fixture/FunctionalComposition']);
        $boundaries = $catalog->search('Page::'.$operation);
        self::assertCount(1, $boundaries);

        $node = (new CacheTree($catalog))->build($boundaries[0]);

        self::assertCount(2, $node->children);
        self::assertSame(20, $node->effect->ttl->seconds);
        self::assertSame(Visibility::Private, $node->effect->visibility);
        self::assertSame(['page', 'product', 'viewer'], $node->effect->tags);
        self::assertSame([], $node->effect->problems);
    }

    public function testCalculateReportsThatAnEmptySequenceCannotSupplyAnAutomaticTtl(): void
    {
        $catalog = (new CatalogLoader(dirname(__DIR__, 5)))
            ->load(['packages/magix-cache-cli/tests/Fixture/FunctionalComposition']);
        $boundaries = $catalog->search('Page::emptySequence');
        self::assertCount(1, $boundaries);

        $node = (new CacheTree($catalog))->build($boundaries[0]);

        self::assertSame([], $node->children);
        self::assertSame(TtlEstimateState::Invalid, $node->effect->ttl->state);
        self::assertSame([
            'Ttl::Auto has no dependency with a finite expiration, so applying the policy throws a LogicException',
        ], $node->effect->problems);
    }

    public function testMissingPolicyKeepsDailyDependencyConstraintsWithTheError(): void
    {
        $expiration = new \Magix\Cache\Cli\Graph\ExpirationEstimate('12:00', '12:15', 'Asia/Tokyo', true);
        $constraint = new DependencyConstraint(expirationConstraints: [$expiration]);
        $effect = (new EffectCalculator())->missingPolicy($constraint, null, Visibility::Shared, null);

        self::assertSame(TtlEstimateState::Invalid, $effect->ttl->state);
        self::assertFalse($effect->storable);
        self::assertSame([$expiration], $effect->expirationConstraints);
        self::assertSame(['no #[Cache] attribute on the method or its concrete class, so cached() throws a LogicException'], $effect->problems);
    }
}
