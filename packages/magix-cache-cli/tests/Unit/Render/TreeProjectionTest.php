<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\TreeProjection;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(TreeProjection::class)]
#[UsesNamespace('Magix\Cache')]
#[Medium]
final class TreeProjectionTest extends TestCase
{
    public function testSelectUsesTheOriginalAncestorEvenWhenProjectingASubtree(): void
    {
        $node = ReportSource::node('Page::automatic')->children[0];
        $projection = new TreeProjection([], UncachedMode::Between);
        [$inside, $declaredBelow] = $projection->select($node, true);
        [$outside] = $projection->select($node, false);
        self::assertTrue($declaredBelow);
        self::assertSame('Bridge::get', $inside[0]->boundary->id());
        self::assertSame('Leaf::get', $outside[0]->boundary->id());
        self::assertSame(['Bridge::get'], $outside[0]->via);
    }

    public function testRootStaysVisibleWithoutActingAsACacheDeclarationForItsCallees(): void
    {
        $projection = new TreeProjection([], UncachedMode::Between);
        $roots = $projection->root(ReportSource::node('Controller::run'));
        self::assertSame('Controller::run', $roots[0]->boundary->id());
        self::assertSame(['Page::unrelated', 'Migration::get'], array_map(static fn ($node): string => $node->boundary->id(), $roots[0]->children));
        self::assertSame([], $projection->root(ReportSource::node('Utility::get'))[0]->children);
        self::assertSame([], (new TreeProjection([new IgnorePattern('Controller')], UncachedMode::Between))->root(ReportSource::node('Controller::run')));
    }

    public function testCalleesProjectsTheCallsOfOneRowAndReportsWhetherTheyReachADeclaration(): void
    {
        $projection = new TreeProjection([], UncachedMode::None);
        [$children, $declares] = $projection->callees(ReportSource::node('Page::automatic'), true);
        self::assertSame(['Leaf::get'], array_map(static fn ($node): string => $node->boundary->id(), $children));
        self::assertTrue($declares);

        [$empty, $withoutDeclaration] = $projection->callees(ReportSource::node('Utility::get'), true);
        self::assertSame([], $empty);
        self::assertFalse($withoutDeclaration);
    }

    public function testPromoteRecordsTheOmittedCallerOnEveryConnectionItStoodOn(): void
    {
        $bridge = ReportSource::node('Page::automatic')->children[0];
        $promoted = (new TreeProjection([], UncachedMode::None))->promote($bridge, $bridge->children);
        self::assertSame('Leaf::get', $promoted[0]->boundary->id());
        self::assertSame(['Bridge::get'], $promoted[0]->via);
        self::assertSame($bridge->children[0]->effect, $promoted[0]->effect);
    }

    public function testIgnoredMatchesTheRowItselfAndNotItsSubtree(): void
    {
        $node = ReportSource::node('Page::automatic');
        self::assertTrue((new TreeProjection([new IgnorePattern('Page')], UncachedMode::Between))->ignored($node));
        self::assertFalse((new TreeProjection([new IgnorePattern('Bridge')], UncachedMode::Between))->ignored($node));
        self::assertFalse((new TreeProjection([], UncachedMode::Between))->ignored($node));
    }
}
