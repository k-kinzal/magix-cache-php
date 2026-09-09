<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheGap;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheGap::class)]
#[CoversClass(CacheTree::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(Visibility::class)]
final class CacheGapTest extends TestCase
{
    #[DataProvider('providerPolicies')]
    public function testLabelIdentifiesIndirectCachesWhilePropagationKeepsProvenBounds(int|Ttl $ttl, ?int $cap, ?int $upper): void
    {
        $child = new BoundaryDeclaration('App\Child', 'get', 'child.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 5, tags: ['child'], visibility: Visibility::NoStore));
        $lookup = new BoundaryDeclaration('App\Lookup', 'get', 'lookup.php', 2, dependencies: [new DependencyCall('App\Child', 'get', 3)], isCacheBoundary: false);
        $bridge = new BoundaryDeclaration('App\Bridge', 'get', 'bridge.php', 4, dependencies: [new DependencyCall('App\Lookup', 'get', 5)], isCacheBoundary: false);
        $parent = new BoundaryDeclaration('App\ParentQuery', 'get', 'parent.php', 6, new PolicyDeclaration(PolicySource::MethodAttribute, $ttl, maxTtl: $cap, tags: ['parent']), dependencies: [new DependencyCall('App\Bridge', 'get', 7)]);
        $outer = new BoundaryDeclaration('App\Outer', 'get', 'outer.php', 8, new PolicyDeclaration(PolicySource::MethodAttribute, 120), dependencies: [new DependencyCall('App\ParentQuery', 'get', 9)]);
        $tree = new CacheTree(new Catalog([
            new ClassDeclaration('App\Child', boundaries: [$child]),
            new ClassDeclaration('App\Lookup', entryPoints: [$lookup]),
            new ClassDeclaration('App\Bridge', entryPoints: [$bridge]),
            new ClassDeclaration('App\ParentQuery', boundaries: [$parent]),
            new ClassDeclaration('App\Outer', boundaries: [$outer]),
        ]));

        $node = $tree->build($parent);

        self::assertCount(1, $node->gaps);
        self::assertSame([$parent, $bridge, $lookup, $child], $node->gaps[0]->path);
        self::assertSame('cache propagation unanalyzed: ParentQuery::get -> Bridge::get -> Lookup::get -> Child::get', $node->gaps[0]->label());
        self::assertSame($child, $node->children[0]->children[0]->children[0]->boundary);
        self::assertSame(is_int($ttl) ? TtlEstimateState::Known : TtlEstimateState::Unknown, $node->effect->ttl->state);
        self::assertSame($upper, $node->effect->ttl->seconds ?? $node->effect->ttl->upperBound);
        self::assertSame(Visibility::Shared, $node->effect->visibility);
        self::assertTrue($node->effect->visibilityUnknown);
        self::assertFalse($node->effect->tagsUnknown);
        self::assertSame(['parent'], $node->effect->tags);
        self::assertFalse($node->effect->storable);
        self::assertSame([], $node->effect->problems);
        self::assertEquals($node->effect, $tree->build($parent, includeUncached: true)->effect);
        $ancestor = $tree->build($outer);
        self::assertFalse($ancestor->effect->storable);
        self::assertTrue($ancestor->effect->visibilityUnknown);
        self::assertFalse($ancestor->effect->tagsUnknown);
        self::assertSame(TtlEstimateState::Known, $ancestor->effect->ttl->state);
        self::assertSame(120, $ancestor->effect->ttl->seconds);
        self::assertSame([], $ancestor->gaps);
        self::assertSame([], $tree->build($bridge)->gaps);
    }

    /**
     * @return iterable<string, array{int|Ttl, int|null, int|null}>
     */
    public static function providerPolicies(): iterable
    {
        yield 'fixed' => [60, null, 60];
        yield 'automatic' => [Ttl::Auto, null, null];
        yield 'upstream cap' => [Ttl::FromUpstream, 30, 30];
    }

    public function testThroughKeepsInterfacePathsDistinctAndStopsAtTheFirstCachedChild(): void
    {
        $child = new BoundaryDeclaration('Child', 'get', 'child.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 5));
        $nested = new BoundaryDeclaration('Nested', 'get', 'nested.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 10), dependencies: [new DependencyCall('Child', 'get', 2)]);
        $first = new BoundaryDeclaration('First', 'get', 'first.php', 1, dependencies: [new DependencyCall('Nested', 'get', 2), new DependencyCall('Nested', 'get', 3)], isCacheBoundary: false);
        $second = new BoundaryDeclaration('Second', 'get', 'second.php', 1, dependencies: [new DependencyCall('Nested', 'get', 2)], isCacheBoundary: false);
        $parent = new BoundaryDeclaration('ParentQuery', 'get', 'parent.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 60), dependencies: [new DependencyCall('Lookup', 'get', 2)]);
        $tree = new CacheTree(new Catalog([
            new ClassDeclaration('Child', boundaries: [$child]),
            new ClassDeclaration('Nested', boundaries: [$nested]),
            new ClassDeclaration('First', parents: ['Lookup'], entryPoints: [$first]),
            new ClassDeclaration('Second', parents: ['Lookup'], entryPoints: [$second]),
            new ClassDeclaration('ParentQuery', boundaries: [$parent]),
        ]));

        $node = $tree->build($parent);

        self::assertCount(2, $node->gaps);
        self::assertSame([$parent, $first, $nested], $node->gaps[0]->path);
        self::assertSame([$parent, $second, $nested], $node->gaps[1]->path);
        self::assertSame(['Lookup::get resolves to 2 implementations'], $node->notes);
        self::assertSame([], $node->children[0]->children[0]->gaps);
    }

    public function testDepthAndRecursionDoNotInventUnseenCacheChildren(): void
    {
        $child = new BoundaryDeclaration('Child', 'get', 'child.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 5));
        $lookup = new BoundaryDeclaration('Lookup', 'get', 'lookup.php', 1, dependencies: [new DependencyCall('Lookup', 'get', 2), new DependencyCall('Child', 'get', 3)], isCacheBoundary: false);
        $parent = new BoundaryDeclaration('ParentQuery', 'get', 'parent.php', 1, new PolicyDeclaration(PolicySource::MethodAttribute, 60), dependencies: [new DependencyCall('Lookup', 'get', 2)]);
        $tree = new CacheTree(new Catalog([
            new ClassDeclaration('Child', boundaries: [$child]),
            new ClassDeclaration('Lookup', entryPoints: [$lookup]),
            new ClassDeclaration('ParentQuery', boundaries: [$parent]),
        ]));

        $limited = $tree->build($parent, 1, includeUncached: true);
        self::assertSame([], $limited->gaps);
        self::assertSame(['depth limit reached, dependencies not expanded'], $limited->children[0]->notes);
        $complete = $tree->build($parent);
        self::assertCount(1, $complete->gaps);
        self::assertSame([$parent, $lookup, $child], $complete->gaps[0]->path);
        self::assertSame(['recursive dependency, not expanded again'], $complete->children[0]->children[0]->notes);
    }
}
