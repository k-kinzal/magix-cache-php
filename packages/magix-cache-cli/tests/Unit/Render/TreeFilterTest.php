<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\IgnorePattern;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TreeFilter::class)]
#[UsesClass(IgnorePattern::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(TtlEstimate::class)]
final class TreeFilterTest extends TestCase
{
    public function testApplyPrunesOnlyMatchingPathsAndPreservesEffectsAndDiagnostics(): void
    {
        $effect = new CacheEffect(TtlEstimate::known(20), Visibility::Private, tags: ['stock'], problems: ['an existing problem']);
        $stock = new CacheNode(new BoundaryDeclaration('StockQuery', 'get', 'stock.php', 1), $effect);
        $manager = new CacheNode(new BoundaryDeclaration('InventoryManager', 'get', 'manager.php', 1, isCacheBoundary: false), $effect, [$stock]);
        $cached = new CacheNode(new BoundaryDeclaration('HiddenQuery', 'get', 'hidden.php', 1), $effect, [$stock]);
        $root = new CacheNode(new BoundaryDeclaration('PageQuery', 'get', 'page.php', 1), $effect, [$manager, $cached, $stock], ['an existing note']);

        $visible = (new TreeFilter([new IgnorePattern('*Manager'), new IgnorePattern('HiddenQuery')]))->apply($root);

        self::assertNotNull($visible);
        self::assertSame($effect, $visible->effect);
        self::assertSame($root->notes, $visible->notes);
        self::assertCount(1, $visible->children);
        self::assertSame($stock->boundary, $visible->children[0]->boundary);
        self::assertCount(3, $root->children);
        self::assertEquals($root, (new TreeFilter())->apply($root));
        self::assertNull((new TreeFilter([new IgnorePattern('PageQuery')]))->apply($root));
    }
}
