<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Render\BoundaryTableRenderer;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundaryTableRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
final class BoundaryTableRendererTest extends TestCase
{
    public function testRenderAlignsEveryColumnUnderItsHeader(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: 20, storable: true, tags: ['product']),
        );

        $table = (new BoundaryTableRenderer())->render([$node]);
        $lines = explode("\n", $table);

        self::assertStringStartsWith('BOUNDARY', $lines[0]);
        self::assertStringStartsWith('App\ProductQuery::execute', $lines[1]);
        self::assertStringContainsString('20s', $lines[1]);
        self::assertSame(strpos($lines[0], 'TTL'), strpos($lines[1], '20s'));
    }

    public function testRowDescribesOneBoundary(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ViewerQuery', 'execute', 'src/ViewerQuery.php', 25),
            new CacheEffect(visibility: Visibility::Private),
        );

        self::assertSame(
            ['App\ViewerQuery::execute', '-', 'private', 'no', '-', '0', 'src/ViewerQuery.php:25'],
            (new BoundaryTableRenderer())->row($node),
        );
    }
}
