<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheGap;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\UncachedMode;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TreeFilter::class)]
#[UsesClass(IgnorePattern::class)]
#[UsesClass(UncachedMode::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheGap::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(TtlEstimate::class)]
final class TreeFilterTest extends TestCase
{
    /**
     * @param list<CacheNode> $expected
     * @param list<CacheNode> $expectedIgnored
     */
    #[DataProvider('providerBranchTrees')]
    public function testApplySelectsOrdinaryRowsWithoutLosingCachedDescendants(UncachedMode $mode, CacheNode $root, array $expected, array $expectedIgnored): void
    {
        $visible = (new TreeFilter(uncached: $mode))->apply($root);

        self::assertNotNull($visible);
        self::assertSame($root->effect, $visible->effect);
        self::assertSame($root->gaps, $visible->gaps);
        self::assertSame($root->notes, $visible->notes);
        self::assertSame($root->boundary, $visible->boundary);
        self::assertEquals($expected, $visible->children);

        $ignored = (new TreeFilter([new IgnorePattern('Bridge')], $mode))->apply($root);
        self::assertNotNull($ignored);
        self::assertEquals($expectedIgnored, $ignored->children);
        self::assertSame($root->gaps, $ignored->gaps);
        self::assertSame($root->effect, $ignored->effect);
    }

    /**
     * @return iterable<string, array{UncachedMode, CacheNode, list<CacheNode>, list<CacheNode>}>
     */
    public static function providerBranchTrees(): iterable
    {
        $effect = new CacheEffect(TtlEstimate::known(20), Visibility::Private);
        $cached = new CacheNode(new BoundaryDeclaration('CachedQuery', 'get', 'cached.php', 1), $effect);
        $leaf = new CacheNode(new BoundaryDeclaration('Leaf', 'get', 'leaf.php', 1, isCacheBoundary: false), $effect);
        $branch = new CacheNode(new BoundaryDeclaration('Branch', 'get', 'branch.php', 1, isCacheBoundary: false), $effect, [$leaf]);
        $inner = new CacheNode(new BoundaryDeclaration('Inner', 'get', 'inner.php', 1, isCacheBoundary: false), $effect, [$cached, $branch]);
        $bridge = new CacheNode(new BoundaryDeclaration('Bridge', 'get', 'bridge.php', 1, isCacheBoundary: false), $effect, [$inner, $branch]);
        $page = new BoundaryDeclaration('Page', 'get', 'page.php', 1);
        $gap = new CacheGap([$page, $bridge->boundary, $inner->boundary, $cached->boundary]);
        $root = new CacheNode($page, $effect, [$bridge, $branch, $cached], ['existing note'], [$gap]);
        $between = new CacheNode($bridge->boundary, $effect, [new CacheNode($inner->boundary, $effect, [$cached])]);

        yield 'between' => [UncachedMode::Between, $root, [$between, $cached], [$cached]];
        yield 'all' => [UncachedMode::All, $root, [$bridge, $branch, $cached], [$branch, $cached]];
        yield 'none' => [UncachedMode::None, $root, [$cached, $cached], [$cached]];
    }

    #[DataProvider('providerUncachedModes')]
    public function testApplyRetainsAnUncachedRootAndHidesPathsBeforeTheFirstCache(UncachedMode $mode): void
    {
        $effect = new CacheEffect(TtlEstimate::unknown(), Visibility::Shared);
        $cached = new CacheNode(new BoundaryDeclaration('CachedQuery', 'get', 'cached.php', 1), $effect);
        $bridge = new CacheNode(new BoundaryDeclaration('Bridge', 'get', 'bridge.php', 1, isCacheBoundary: false), $effect, [$cached]);
        $root = new CacheNode(new BoundaryDeclaration('Controller', 'get', 'controller.php', 1, isCacheBoundary: false), $effect, [$bridge]);

        $visible = (new TreeFilter(uncached: $mode))->apply($root);

        self::assertNotNull($visible);
        self::assertSame($root->boundary, $visible->boundary);
        self::assertEquals($mode === UncachedMode::All ? [$bridge] : [$cached], $visible->children);
        self::assertSame($effect, $visible->effect);
        self::assertEquals(new CacheNode($root->boundary, $effect), (new TreeFilter([new IgnorePattern('Bridge')], $mode))->apply($root));
        self::assertNull((new TreeFilter([new IgnorePattern('Controller')], $mode))->apply($root));
        self::assertEquals($cached, (new TreeFilter(uncached: $mode))->apply($cached));
    }

    public function testApplyPreservesWarningsEvenWhenOrdinaryRowsAreHidden(): void
    {
        $effect = new CacheEffect(TtlEstimate::unknown(), Visibility::Shared);
        $stopped = new CacheNode(new BoundaryDeclaration('Lookup', 'get', 'lookup.php', 1, isCacheBoundary: false), $effect, notes: ['returned cache metadata is not analyzed']);
        $root = new CacheNode(new BoundaryDeclaration('CachedQuery', 'get', 'cached.php', 1), $effect, [$stopped]);

        self::assertEquals(new CacheNode($root->boundary, $effect, analysisWarnings: $root->analysisWarnings), (new TreeFilter())->apply($root));
        self::assertEquals($root, (new TreeFilter(uncached: UncachedMode::All))->apply($root));
        self::assertEquals($stopped, (new TreeFilter(uncached: UncachedMode::None))->apply($stopped));
    }

    /**
     * @return iterable<string, array{UncachedMode}>
     */
    public static function providerUncachedModes(): iterable
    {
        foreach (UncachedMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    public function testApplyPrunesOnlyMatchingPathsAndPreservesEffectsAndDiagnostics(): void
    {
        $effect = new CacheEffect(TtlEstimate::known(20), Visibility::Private, tags: ['stock'], problems: ['an existing problem']);
        $stock = new CacheNode(new BoundaryDeclaration('StockQuery', 'get', 'stock.php', 1), $effect);
        $manager = new CacheNode(new BoundaryDeclaration('InventoryManager', 'get', 'manager.php', 1, isCacheBoundary: false), $effect, [$stock]);
        $cached = new CacheNode(new BoundaryDeclaration('HiddenQuery', 'get', 'hidden.php', 1), $effect, [$stock]);
        $root = new CacheNode(new BoundaryDeclaration('PageQuery', 'get', 'page.php', 1), $effect, [$manager, $cached, $stock], ['an existing note']);

        $root = new CacheNode($root->boundary, $effect, $root->children, $root->notes, [new CacheGap([$root->boundary, $manager->boundary, $stock->boundary])]);

        $visible = (new TreeFilter([new IgnorePattern('*Manager'), new IgnorePattern('HiddenQuery')]))->apply($root);

        self::assertNotNull($visible);
        self::assertSame($effect, $visible->effect);
        self::assertSame($root->notes, $visible->notes);
        self::assertSame($root->gaps, $visible->gaps);
        self::assertCount(1, $visible->children);
        self::assertSame($stock->boundary, $visible->children[0]->boundary);
        self::assertCount(3, $root->children);
        self::assertEquals($root, (new TreeFilter())->apply($root));
        self::assertNull((new TreeFilter([new IgnorePattern('PageQuery')]))->apply($root));
    }
}
