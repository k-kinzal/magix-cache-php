<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Render\TreeProjection;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(TreeProjection::class)]
#[UsesNamespace('Magix\Cache')]
final class TreeProjectionTest extends TestCase
{
    public function testSelectUsesTheOriginalAncestorEvenWhenProjectingASubtree(): void
    {
        $node = ReportSource::node('Page::automatic')->children[0];
        $projection = new TreeProjection([], UncachedMode::Between);
        [$inside, $declaredBelow] = $projection->select($node, true, 8);
        [$outside] = $projection->select($node, false, 8);
        self::assertTrue($declaredBelow);
        self::assertSame('Bridge::get', $inside[0]->boundary->id());
        self::assertSame('Leaf::get', $outside[0]->boundary->id());
        self::assertSame(['Bridge::get'], $outside[0]->via);
    }
}
