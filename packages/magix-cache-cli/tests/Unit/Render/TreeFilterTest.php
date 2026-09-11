<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(TreeFilter::class)]
#[UsesNamespace('Magix\Cache')]
#[Medium]
final class TreeFilterTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('providerModes')]
    public function testApplyUsesAttributesEvenWhenTypesExecutionAndProblemsDisagree(UncachedMode $mode, array $expected): void
    {
        $original = ReportSource::node('Sandwich::get');
        $visible = (new TreeFilter(uncached: $mode))->apply($original);
        self::assertCount(1, $visible);
        self::assertSame($original->effect, $visible[0]->effect);
        self::assertSame($expected, array_map(static fn ($node): string => $node->boundary->id(), $visible[0]->children));
        self::assertNotEmpty($original->effect->problems);
    }

    /**
     * @return iterable<string, array{UncachedMode, list<string>}>
     */
    public static function providerModes(): iterable
    {
        yield 'all' => [UncachedMode::All, ['Utility::get', 'TypedOnly::get', 'ExecutionOnly::get']];
        yield 'between' => [UncachedMode::Between, ['TypedOnly::get', 'ExecutionOnly::get']];
        yield 'none' => [UncachedMode::None, ['Leaf::get', 'Leaf::get']];
    }

    #[DataProvider('providerRootModes')]
    public function testApplyRetainsTheAnalyzedRootAndPromotesDeclarationsUnderIt(UncachedMode $mode): void
    {
        $original = ReportSource::node('Controller::run');
        $visible = (new TreeFilter(uncached: $mode))->apply($original);
        self::assertCount(1, $visible);
        self::assertSame('Controller::run', $visible[0]->boundary->id());
        self::assertSame([], $visible[0]->via);
        self::assertSame($original->effect, $visible[0]->effect);
        self::assertSame(['Page::unrelated', 'Migration::get'], array_map(static fn ($node): string => $node->boundary->id(), $visible[0]->children));
        self::assertSame($original->children[0]->effect, $visible[0]->children[0]->effect);
    }

    /**
     * @return iterable<string, array{UncachedMode}>
     */
    public static function providerRootModes(): iterable
    {
        yield 'none' => [UncachedMode::None];
        yield 'between' => [UncachedMode::Between];
    }

    public function testApplyKeepsUnattributedCalleesOfTheRootOnlyInAll(): void
    {
        $visible = (new TreeFilter(uncached: UncachedMode::All))->apply(ReportSource::node('Controller::run'));
        self::assertSame(
            ['Page::unrelated', 'Migration::get', 'Utility::get'],
            array_map(static fn ($node): string => $node->boundary->id(), $visible[0]->children),
        );
    }

    public function testApplyHonorsIgnoreBeforePromotionAndCountsPrintedRows(): void
    {
        $original = ReportSource::node('Page::automatic');
        $visible = (new TreeFilter(uncached: UncachedMode::None))->apply($original);
        self::assertSame(['Bridge::get'], $visible[0]->children[0]->via);
        self::assertSame($original->effect, $visible[0]->effect);
        self::assertSame([], (new TreeFilter([new IgnorePattern('Bridge')], UncachedMode::None))->apply($original)[0]->children);
        self::assertSame('Leaf::get', (new TreeFilter(uncached: UncachedMode::None, depth: 1))->apply($original)[0]->children[0]->boundary->id());
        self::assertSame([], (new TreeFilter(uncached: UncachedMode::None, depth: 0))->apply($original)[0]->children);
        self::assertSame([], (new TreeFilter([new IgnorePattern('Page')]))->apply($original));
        self::assertSame([], (new TreeFilter(uncached: UncachedMode::None))->apply(ReportSource::node('Utility::get'))[0]->children);
    }

    public function testApplyBetweenUsesTheHierarchyBeyondDisplayDepth(): void
    {
        $visible = (new TreeFilter(depth: 1))->apply(ReportSource::node('Page::automatic'));
        self::assertSame('Bridge::get', $visible[0]->children[0]->boundary->id());
        self::assertSame([], $visible[0]->children[0]->children);
        self::assertSame([], (new TreeFilter([new IgnorePattern('Leaf')]))->apply(ReportSource::node('Page::automatic'))[0]->children);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('providerPrintedDepths')]
    public function testApplySpendsDisplayDepthOnPrintedRowsOnly(UncachedMode $mode, array $expected): void
    {
        $effect = new CacheEffect(TtlEstimate::unknown());
        $policy = new PolicyDeclaration(PolicySource::MethodAttribute);
        $leaf = new CacheNode(new BoundaryDeclaration('Leaf', 'get', 'leaf.php', 1, policy: $policy), $effect);
        $bridge = new CacheNode(new BoundaryDeclaration('Bridge', 'get', 'bridge.php', 1), $effect, [$leaf]);
        $page = new CacheNode(new BoundaryDeclaration('Page', 'get', 'page.php', 1, policy: $policy), $effect, [$bridge]);
        $inner = new CacheNode(new BoundaryDeclaration('Inner', 'run', 'inner.php', 1), $effect, [$page]);
        $outer = new CacheNode(new BoundaryDeclaration('Outer', 'run', 'outer.php', 1), $effect, [$inner]);
        $root = new CacheNode(new BoundaryDeclaration('Controller', 'index', 'controller.php', 1), $effect, [$outer]);

        $rows = [];
        $walk = static function (CacheNode $node) use (&$walk, &$rows): void {
            $rows[] = $node->boundary->id();

            foreach ($node->children as $child) {
                $walk($child);
            }
        };
        $walk((new TreeFilter(uncached: $mode, depth: 3))->apply($root)[0]);

        self::assertSame($expected, $rows);
    }

    public function testTruncateKeepsOnlyTheRowsWithinThePrintedDepth(): void
    {
        $filter = new TreeFilter();
        $nodes = $filter->apply(ReportSource::node('Page::automatic'));
        self::assertSame(['Page::automatic', 'Bridge::get', 'Leaf::get'], [
            $nodes[0]->boundary->id(), $nodes[0]->children[0]->boundary->id(), $nodes[0]->children[0]->children[0]->boundary->id(),
        ]);
        self::assertSame([], $filter->truncate($nodes, -1));
        self::assertSame([], $filter->truncate($nodes, 0)[0]->children);
        self::assertSame([], $filter->truncate($nodes, 1)[0]->children[0]->children);
        self::assertSame('Leaf::get', $filter->truncate($nodes, 2)[0]->children[0]->children[0]->boundary->id());
    }

    /**
     * @return iterable<string, array{UncachedMode, list<string>}>
     */
    public static function providerPrintedDepths(): iterable
    {
        yield 'all stops at the display depth' => [UncachedMode::All, ['Controller::index', 'Outer::run', 'Inner::run', 'Page::get']];
        yield 'between spends nothing on omitted callers' => [UncachedMode::Between, ['Controller::index', 'Page::get', 'Bridge::get', 'Leaf::get']];
        yield 'none promotes declarations within the same depth' => [UncachedMode::None, ['Controller::index', 'Page::get', 'Leaf::get']];
    }
}
