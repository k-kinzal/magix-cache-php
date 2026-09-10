<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(TreeFilter::class)]
#[UsesNamespace('Magix\Cache')]
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
    public function testApplyPromotesAnUndeclaredRootToAForest(UncachedMode $mode): void
    {
        $original = ReportSource::node('Controller::run');
        $visible = (new TreeFilter(uncached: $mode))->apply($original);
        self::assertSame(['Page::unrelated', 'Migration::get'], array_map(static fn ($node): string => $node->boundary->id(), $visible));
        self::assertSame(['Controller::run'], $visible[0]->via);
        self::assertSame($original->children[0]->effect, $visible[0]->effect);
        self::assertSame('Controller::run', (new TreeFilter(uncached: UncachedMode::All))->apply($original)[0]->boundary->id());
    }

    /**
     * @return iterable<string, array{UncachedMode}>
     */
    public static function providerRootModes(): iterable
    {
        yield 'none' => [UncachedMode::None];
        yield 'between' => [UncachedMode::Between];
    }

    public function testApplyHonorsIgnoreBeforePromotionAndCountsOriginalDepth(): void
    {
        $original = ReportSource::node('Page::automatic');
        $visible = (new TreeFilter(uncached: UncachedMode::None))->apply($original);
        self::assertSame(['Bridge::get'], $visible[0]->children[0]->via);
        self::assertSame($original->effect, $visible[0]->effect);
        self::assertSame([], (new TreeFilter([new IgnorePattern('Bridge')], UncachedMode::None))->apply($original)[0]->children);
        self::assertSame([], (new TreeFilter(uncached: UncachedMode::None, depth: 1))->apply($original)[0]->children);
        self::assertSame([], (new TreeFilter([new IgnorePattern('Page')]))->apply($original));
        self::assertSame([], (new TreeFilter(uncached: UncachedMode::None))->apply(ReportSource::node('Utility::get')));
    }

    public function testApplyBetweenUsesTheHierarchyBeyondDisplayDepth(): void
    {
        $visible = (new TreeFilter(depth: 1))->apply(ReportSource::node('Page::automatic'));
        self::assertSame('Bridge::get', $visible[0]->children[0]->boundary->id());
        self::assertSame([], $visible[0]->children[0]->children);
        self::assertSame([], (new TreeFilter([new IgnorePattern('Leaf')]))->apply(ReportSource::node('Page::automatic'))[0]->children);
    }
}
