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
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTree::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyConstraint::class)]
#[UsesClass(EffectCalculator::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\StrategyResolver::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ParameterEffects::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ParameterStrategyBinding::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class CacheTreeTest extends TestCase
{
    public function testBuildKeepsUncachedRootDepthLimitsAndRecursionUnknown(): void
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

        $limited = $tree->build($root, 0);
        self::assertSame(TtlEstimateState::Unknown, $limited->effect->ttl->state);
        self::assertSame([], $limited->effect->problems);
        self::assertSame(['depth limit reached, dependencies not expanded'], $limited->notes);
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

        self::assertSame(20, $node->effect->ttl->seconds);
        self::assertCount(1, $node->children);
        self::assertSame('App\ProductQuery::execute', $node->children[0]->boundary->id());
    }

    public function testBuildStopsAtRecursiveAndTooDeepDependencies(): void
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
        $limited = $tree->build($boundary, 0);

        self::assertSame(['recursive dependency, not expanded again'], $recursive->children[0]->notes);
        self::assertSame(TtlEstimateState::Unknown, $recursive->children[0]->effect->ttl->state);
        self::assertSame([], $limited->children);
        self::assertSame(['depth limit reached, dependencies not expanded'], $limited->notes);
        self::assertSame(TtlEstimateState::Unknown, $limited->effect->ttl->state);
        self::assertSame(20, $limited->effect->ttl->upperBound);
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
        self::assertSame(5, $node->effect->ttl->seconds);
    }
}
