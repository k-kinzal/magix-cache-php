<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\UncachedMode;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UncachedMode::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(TtlEstimate::class)]
final class UncachedModeTest extends TestCase
{
    /**
     * @param list<UncachedMode> $expected
     */
    #[DataProvider('providerRows')]
    public function testKeepsOnlyRowsSelectedByTheDisplayMode(CacheNode $node, bool $cachedAncestor, array $expected): void
    {
        self::assertSame($expected, array_values(array_filter(UncachedMode::cases(), static fn (UncachedMode $mode): bool => $mode->keeps($node, $cachedAncestor))));
    }

    /**
     * @return iterable<string, array{CacheNode, bool, list<UncachedMode>}>
     */
    public static function providerRows(): iterable
    {
        $effect = new CacheEffect(TtlEstimate::unknown(), Visibility::Shared);
        $cached = new CacheNode(new BoundaryDeclaration('CachedQuery', 'get', 'cached.php', 1), $effect);
        $leaf = new CacheNode(new BoundaryDeclaration('Leaf', 'get', 'leaf.php', 1, isCacheBoundary: false), $effect);
        $bridge = new CacheNode($leaf->boundary, $effect, [$cached]);

        yield 'cached after a cache' => [$cached, true, [UncachedMode::Between, UncachedMode::All, UncachedMode::None]];
        yield 'first cache' => [$cached, false, [UncachedMode::Between, UncachedMode::All, UncachedMode::None]];
        yield 'ordinary leaf after a cache' => [$leaf, true, [UncachedMode::All]];
        yield 'ordinary leaf before a cache' => [$leaf, false, [UncachedMode::All]];
        yield 'ordinary bridge between caches' => [$bridge, true, [UncachedMode::Between, UncachedMode::All]];
        yield 'ordinary bridge before the first cache' => [$bridge, false, [UncachedMode::All]];
    }
}
