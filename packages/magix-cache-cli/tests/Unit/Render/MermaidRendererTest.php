<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MermaidRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
final class MermaidRendererTest extends TestCase
{
    public function testRenderStartsAFlowchart(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: 20),
        );

        $chart = (new MermaidRenderer())->render($node);

        self::assertStringStartsWith("flowchart TD\n", $chart);
        self::assertStringContainsString('n0["ProductQuery::execute<br/>20s - shared"]', $chart);
    }

    public function testStatementsConnectEveryDependency(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ViewerQuery', 'execute', 'src/ViewerQuery.php', 12),
            new CacheEffect(visibility: Visibility::Private),
        );
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(ttl: 20),
            [$child],
        );

        $statements = (new MermaidRenderer())->statements($node, 'n0');

        self::assertSame('    n0_0["ViewerQuery::execute<br/>no expiration - private"]', $statements[1]);
        self::assertSame('    n0 --> n0_0', $statements[2]);
    }
}
