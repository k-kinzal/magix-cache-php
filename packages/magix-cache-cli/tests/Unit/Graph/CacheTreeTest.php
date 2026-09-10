<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTree::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\CacheGap::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyConstraint::class)]
#[UsesClass(EffectCalculator::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\StrategyResolver::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ParameterEffects::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\LocalOverrides::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ParameterStrategyBinding::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class CacheTreeTest extends TestCase
{
    public function testExpandBoundsOrdinaryRecursionWithoutChangingCacheComposition(): void
    {
        $loop = new BoundaryDeclaration('Lookup', 'get', 'lookup.php', 1, dependencies: [new DependencyCall('Lookup', 'get', 2)], isCacheBoundary: false);
        $root = new BoundaryDeclaration('Page', 'get', 'page.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 60), dependencies: [new DependencyCall('Lookup', 'get', 2)]);
        $tree = new CacheTree(new Catalog([
            new ClassDeclaration('Page', boundaries: [$root]),
            new ClassDeclaration('Lookup', entryPoints: [$loop]),
        ]));

        $baseline = $tree->build($root);
        $expanded = $tree->expand($root, [$root->id()], true);
        $uncached = $tree->build($root, includeUncached: true);

        self::assertSame([], $baseline->children);
        self::assertEquals($baseline->effect, $expanded->effect);
        self::assertEquals($baseline->effect, $uncached->effect);
        self::assertSame(['recursive dependency, not expanded again'], $expanded->children[0]->children[0]->notes);
        self::assertSame(TtlEstimateState::Unknown, $expanded->children[0]->children[0]->effect->ttl->state);
    }

    public function testBuildExplicitVisibilityOverridesRecursiveDependencies(): void
    {
        $inner = new BoundaryDeclaration('InnerQuery', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 60), dependencies: [new DependencyCall('InnerQuery', 'execute', 2)]);
        $outer = new BoundaryDeclaration('OuterQuery', 'execute', 'b.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 20, visibility: \Magix\Cache\Metadata\Visibility::Private), dependencies: [new DependencyCall('InnerQuery', 'execute', 2)]);
        $tree = new CacheTree(new Catalog([
            new ClassDeclaration('InnerQuery', boundaries: [$inner]),
            new ClassDeclaration('OuterQuery', boundaries: [$outer]),
        ]));
        $recursive = $tree->build($outer);

        self::assertFalse($recursive->effect->visibilityUnknown);
        self::assertArrayHasKey('visibility', $recursive->effect->localOverrides);
    }

    public function testBuildKeepsAnUncachedRootWithRecursionUnknown(): void
    {
        $query = new BoundaryDeclaration('App\ProductQuery', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 20));
        $root = new BoundaryDeclaration(
            'App\Controller',
            'index',
            'b.php',
            1,
            dependencies: [
                new DependencyCall('App\ProductQuery', 'execute', 2),
                new DependencyCall('App\ProductQuery', 'execute', 3),
                new DependencyCall('App\Controller', 'index', 4),
            ],
            isCacheBoundary: false,
        );
        $tree = new CacheTree(new Catalog([
            new ClassDeclaration('App\ProductQuery', boundaries: [$query]),
            new ClassDeclaration('App\Controller', entryPoints: [$root]),
        ]));

        $recursive = $tree->build($root);
        self::assertCount(2, $recursive->children);
        self::assertSame(TtlEstimateState::Unknown, $recursive->effect->ttl->state);
        self::assertSame(20, $recursive->effect->ttl->upperBound);
        self::assertSame([], $recursive->effect->problems);
        self::assertSame(['recursive dependency, not expanded again'], $recursive->children[1]->notes);
    }

    public function testBuildComposesTheEffectOfEveryDependency(): void
    {
        $product = new BoundaryDeclaration(
            class: 'App\ProductQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 20),
        );
        $page = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'b.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120),
            dependencies: [new DependencyCall('App\ProductQuery', 'execute', 5)],
        );
        $catalog = new Catalog([
            new ClassDeclaration('App\ProductQuery', [], [$product]),
            new ClassDeclaration('App\PageQuery', [], [$page]),
        ]);

        $node = (new CacheTree($catalog))->build($page);

        self::assertSame(120, $node->effect->ttl->seconds);
        self::assertCount(1, $node->children);
        self::assertSame('App\ProductQuery::execute', $node->children[0]->boundary->id());
    }

    public function testBuildStopsAtARecursiveDependencyWithoutLosingTheLocalPolicy(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\LoopQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 20),
            dependencies: [new DependencyCall('App\LoopQuery', 'execute', 5)],
        );
        $catalog = new Catalog([new ClassDeclaration('App\LoopQuery', [], [$boundary])]);
        $tree = new CacheTree($catalog);

        $recursive = $tree->build($boundary);

        self::assertSame(['recursive dependency, not expanded again'], $recursive->children[0]->notes);
        self::assertSame(TtlEstimateState::Unknown, $recursive->children[0]->effect->ttl->state);
        self::assertSame(TtlEstimateState::Known, $recursive->effect->ttl->state);
        self::assertSame(20, $recursive->effect->ttl->seconds);
    }

    public function testBuildNotesWhenACallHasSeveralImplementations(): void
    {
        $first = new BoundaryDeclaration('App\FirstFeedQuery', 'execute', 'a.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 20));
        $second = new BoundaryDeclaration('App\SecondFeedQuery', 'execute', 'b.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 5));
        $home = new BoundaryDeclaration(
            class: 'App\HomeQuery',
            method: 'execute',
            file: 'c.php',
            line: 1,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120),
            dependencies: [new DependencyCall('App\FeedQuery', 'execute', 5)],
        );
        $catalog = new Catalog([
            new ClassDeclaration('App\FeedQuery'),
            new ClassDeclaration('App\FirstFeedQuery', ['App\FeedQuery'], [$first]),
            new ClassDeclaration('App\SecondFeedQuery', ['App\FeedQuery'], [$second]),
            new ClassDeclaration('App\HomeQuery', [], [$home]),
        ]);

        $node = (new CacheTree($catalog))->build($home);

        self::assertSame(['App\FeedQuery::execute resolves to 2 implementations'], $node->notes);
        self::assertSame(120, $node->effect->ttl->seconds);
    }
    public function testFinishSummarizesReturnAlternativesWithoutMeetingThem(): void
    {
        $node = \Tests\Package\Cli\Fixture\AnalysisSource::node('return $flag ? $this->inputs->a() : $this->inputs->b();');
        self::assertSame('20/60s', $node->effect->ttl->label());
        self::assertSame([], $node->gaps);
    }

    public function testUnverifiedRetainsOnlyOpaquePropagationDiagnostics(): void
    {
        $known = \Tests\Package\Cli\Fixture\AnalysisSource::node('return $this->inputs->a();');
        $opaque = \Tests\Package\Cli\Fixture\AnalysisSource::node('return transform($this->inputs->a());');
        self::assertSame([], $known->gaps);
        self::assertCount(1, $opaque->gaps);
    }

}
