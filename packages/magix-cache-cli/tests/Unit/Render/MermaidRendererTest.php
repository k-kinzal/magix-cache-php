<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheGap;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\ExpirationEstimate;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Cli\Render\NodePresentation;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(MermaidRenderer::class)]
#[UsesNamespace('Magix\Cache')]
#[UsesClass(NodePresentation::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheGap::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(ExpirationEstimate::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
#[UsesClass(\Magix\Cache\Cli\Render\AlternativePresentation::class)]
#[Medium]
final class MermaidRendererTest extends TestCase
{
    public function testRenderIdentifiesAndHighlightsAnUnverifiedCachePath(): void
    {
        $parent = new BoundaryDeclaration('App\PageQuery', 'get', 'page.php', 1);
        $bridge = new BoundaryDeclaration('App\Lookup', 'get', 'lookup.php', 1, isCacheBoundary: false);
        $child = new BoundaryDeclaration('App\ProductQuery', 'get', 'product.php', 1);
        $effect = new CacheEffect(TtlEstimate::unknown(60), expirationConstraints: [new ExpirationEstimate('12:00', '12:15', 'Asia/Tokyo', true)]);
        $node = new CacheNode($parent, $effect, gaps: [new CacheGap([$parent, $bridge, $child])]);

        $chart = (new MermaidRenderer())->render($node);

        self::assertStringNotContainsString('cache propagation unanalyzed', $chart);
        self::assertStringContainsString('expires by daily 12:00-12:15 Asia/Tokyo', $chart);
        self::assertStringNotContainsString('fill:#fff3cd', $chart);
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

        self::assertSame('    n0_0["ViewerQuery::execute<br/>unconstrained - private"]', $statements[2]);
        self::assertSame('    n0 --> n0_0', $statements[4]);
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
    public function testForestEmitsOneDiagramWithUniqueRootIdentifiers(): void
    {
        $filter = new \Magix\Cache\Cli\Render\TreeFilter(uncached: \Magix\Cache\Cli\Render\UncachedMode::None);
        $roots = [
            ...$filter->apply(\Tests\Package\Cli\Fixture\ReportSource::node('Page::unrelated')),
            ...$filter->apply(\Tests\Package\Cli\Fixture\ReportSource::node('Migration::get')),
        ];
        $chart = (new MermaidRenderer())->forest($roots);
        self::assertSame(1, substr_count($chart, 'flowchart TD'));
        self::assertStringContainsString('n0["Page::unrelated', $chart);
        self::assertStringContainsString('n1["Migration::get', $chart);
        self::assertStringNotContainsString('Controller', $chart);
    }

    public function testRenderShowsTheSameMetadataReferencesAsTheTree(): void
    {
        $node = \Tests\Package\Cli\Fixture\ReportSource::node('Page::automatic');
        $node = (new \Magix\Cache\Cli\Render\TreeFilter(uncached: \Magix\Cache\Cli\Render\UncachedMode::None))->apply($node)[0];
        $chart = (new MermaidRenderer())->render($node);
        self::assertStringContainsString('Page::automatic<br/>10s? - shared? - tags leaf?', $chart);
        self::assertStringContainsString('Leaf::get<br/>10s - shared - tags leaf', $chart);
        self::assertStringNotContainsString('unanalyzed', $chart);
    }
}
