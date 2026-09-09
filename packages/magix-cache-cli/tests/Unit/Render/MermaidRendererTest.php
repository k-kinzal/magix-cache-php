<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheGap;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MermaidRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheGap::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class MermaidRendererTest extends TestCase
{
    public function testRenderIdentifiesAndHighlightsAnUnverifiedCachePath(): void
    {
        $parent = new BoundaryDeclaration('App\PageQuery', 'get', 'page.php', 1);
        $bridge = new BoundaryDeclaration('App\Lookup', 'get', 'lookup.php', 1, isCacheBoundary: false);
        $child = new BoundaryDeclaration('App\ProductQuery', 'get', 'product.php', 1);
        $node = new CacheNode($parent, new CacheEffect(TtlEstimate::unknown(60)), gaps: [new CacheGap([$parent, $bridge, $child])]);

        $chart = (new MermaidRenderer())->render($node);

        self::assertStringContainsString('cache propagation unanalyzed: PageQuery::get → Lookup::get → ProductQuery::get', $chart);
        self::assertStringContainsString('style n0 fill:#f8d7da,stroke:#b02a37,color:#842029', $chart);
    }

    public function testRenderStartsAFlowchart(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::known(20)),
        );

        $chart = (new MermaidRenderer())->render($node);

        self::assertStringStartsWith("flowchart TD\n", $chart);
        self::assertStringContainsString('n0["ProductQuery::execute<br/>20s - shared"]', $chart);
    }

    public function testStatementsConnectEveryDependency(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ViewerQuery', 'execute', 'src/ViewerQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::unconstrained(), visibility: Visibility::Private),
        );
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(ttl: TtlEstimate::known(20)),
            [$child],
        );

        $statements = (new MermaidRenderer())->statements($node, 'n0');

        self::assertSame('    n0_0["ViewerQuery::execute<br/>unconstrained - private"]', $statements[1]);
        self::assertSame('    n0 --> n0_0', $statements[2]);
    }

    public function testStatementsShowConditionalUpperBounds(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\RateQuery', 'execute', 'src/RateQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::unknown(30)),
        );

        $statements = (new MermaidRenderer())->statements($node, 'n0');

        self::assertSame('    n0["RateQuery::execute<br/>≤30s - shared"]', $statements[0]);
    }
}
