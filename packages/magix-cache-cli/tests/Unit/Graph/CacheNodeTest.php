<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheNode::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class CacheNodeTest extends TestCase
{
    public function testNodeCarriesItsBoundaryEffectAndChildren(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'a.php', 1),
            new CacheEffect(ttl: TtlEstimate::known(20)),
        );

        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'b.php', 1),
            new CacheEffect(ttl: TtlEstimate::known(20)),
            [$child],
            ['recursive dependency'],
        );

        self::assertSame('App\PageQuery::execute', $node->boundary->id());
        self::assertSame([$child], $node->children);
        self::assertSame(['recursive dependency'], $node->notes);
        self::assertSame(['PageQuery::execute: recursive dependency'], $node->analysisWarnings);
        $ancestor = new CacheNode($node->boundary, $node->effect, [$node]);
        self::assertSame($node->analysisWarnings, $ancestor->analysisWarnings);
    }
}
