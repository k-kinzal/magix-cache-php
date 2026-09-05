<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\BoundaryTableRenderer;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundaryTableRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(TtlEstimate::class)]
final class BoundaryTableRendererTest extends TestCase
{
    public function testRenderAlignsEveryColumnUnderItsHeader(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::known(20), storable: true, tags: ['product']),
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
        $unconstrained = new CacheNode(
            new BoundaryDeclaration('App\ViewerQuery', 'execute', 'src/ViewerQuery.php', 25),
            new CacheEffect(ttl: TtlEstimate::unconstrained(), visibility: Visibility::Private),
        );
        $conditional = new CacheNode(
            new BoundaryDeclaration('App\RateQuery', 'execute', 'src/RateQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::unknown(60)),
        );

        self::assertSame(
            ['App\ViewerQuery::execute', 'unconstrained', 'private', 'no', '-', '0', 'src/ViewerQuery.php:25'],
            (new BoundaryTableRenderer())->row($unconstrained),
        );
        self::assertSame(
            ['App\RateQuery::execute', 'unknown (≤60s)', 'shared', 'no', '-', '0', 'src/RateQuery.php:12'],
            (new BoundaryTableRenderer())->row($conditional),
        );
    }
}
