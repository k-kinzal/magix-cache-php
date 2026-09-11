<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheNode::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
#[Medium]
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
        self::assertCount(1, $node->diagnostics);
        $ancestor = new CacheNode($node->boundary, $node->effect, [$node]);
        self::assertSame([], $ancestor->diagnostics);
    }
    public function testStorageDoesNotDependOnUnrelatedDescendants(): void
    {
        self::assertSame('yes', \Tests\Package\Cli\Fixture\ReportSource::node('Page::unrelated')->storage());
        self::assertSame('unknown', \Tests\Package\Cli\Fixture\ReportSource::node('Page::fixed')->storage());
        self::assertSame('no', \Tests\Package\Cli\Fixture\ReportSource::node('Migration::get')->storage());
    }

    public function testCompositionAnswersWithTheStoredResultOrWhatTheReachedBoundariesBound(): void
    {
        $page = \Tests\Package\Cli\Fixture\ReportSource::node('Page::unrelated');
        self::assertSame($page->effect, $page->composition());

        $controller = \Tests\Package\Cli\Fixture\ReportSource::node('Controller::run');
        self::assertSame(10, $controller->composition()->ttl->seconds);
        self::assertSame(['leaf'], $controller->composition()->tags);
        self::assertNull($controller->effect->ttl->seconds);

        $inspection = \Tests\Package\Cli\Fixture\ReportSource::node('Utility::get');
        self::assertSame('unconstrained', $inspection->composition()->ttl->state->value);
    }

    public function testWithChildrenRetainsFactsWhenProjectionOmitsTheOrigin(): void
    {
        $original = \Tests\Package\Cli\Fixture\ReportSource::node('Page::automatic');
        $visible = $original->withChildren([], ['Controller::run']);
        self::assertSame([], $visible->children);
        self::assertSame(['Controller::run'], $visible->via);
        self::assertSame($original->effect, $visible->effect);
        self::assertSame($original->composed, $visible->composed);
        self::assertSame($original->diagnostics, $visible->diagnostics);
        self::assertSame($original->metadataVariants, $visible->metadataVariants);
        self::assertSame($original->calls, $visible->calls);
        self::assertNotEmpty($original->children);
    }
}
