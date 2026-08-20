<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheNode::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
final class CacheNodeTest extends TestCase
{
    public function testNodeCarriesItsBoundaryEffectAndChildren(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'a.php', 1),
            new CacheEffect(ttl: 20),
        );

        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'b.php', 1),
            new CacheEffect(ttl: 20),
            [$child],
            ['recursive dependency'],
        );

        self::assertSame('App\PageQuery::execute', $node->boundary->id());
        self::assertSame([$child], $node->children);
        self::assertSame(['recursive dependency'], $node->notes);
    }
}
