<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ConstantCatalog;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantCatalog::class)]
final class ConstantCatalogTest extends TestCase
{
    public function testClassConstantReadsTheDeclaringClass(): void
    {
        $ttl = new Int_(300);
        $catalog = new ConstantCatalog(['Config' => ['TTL' => $ttl]]);

        self::assertSame($ttl, $catalog->classConstant('Config', 'TTL'));
        self::assertNull($catalog->classConstant('Config', 'MISSING'));
        self::assertNull($catalog->classConstant('Unknown', 'TTL'));
    }

    public function testClassConstantFollowsParentsAndInterfaces(): void
    {
        $ttl = new Int_(60);
        $name = new String_('page');
        $catalog = new ConstantCatalog(
            ['Base' => ['TTL' => $ttl], 'Contract' => ['NAME' => $name]],
            ['Child' => ['Base', 'Contract']],
        );

        self::assertSame($ttl, $catalog->classConstant('Child', 'TTL'));
        self::assertSame($name, $catalog->classConstant('Child', 'NAME'));
    }

    public function testClassConstantStopsOnACycleInTheInheritanceRecord(): void
    {
        $catalog = new ConstantCatalog([], ['A' => ['B'], 'B' => ['A']]);

        self::assertNull($catalog->classConstant('A', 'TTL'));
    }

    public function testGlobalConstantReadsANamespacedDeclaration(): void
    {
        $window = new Int_(45);
        $catalog = new ConstantCatalog(globals: ['App\TTL' => $window]);

        self::assertSame($window, $catalog->globalConstant('App\TTL'));
        self::assertNull($catalog->globalConstant('App\MISSING'));
    }

    public function testMergeCombinesTheConstantsOfBothSources(): void
    {
        $x = new Int_(1);
        $g = new Int_(2);
        $y = new Int_(3);
        $merged = (new ConstantCatalog(['A' => ['X' => $x]], globals: ['G' => $g]))
            ->merge(new ConstantCatalog(['B' => ['Y' => $y]], ['B' => ['A']]));

        self::assertSame($x, $merged->classConstant('A', 'X'));
        self::assertSame($y, $merged->classConstant('B', 'Y'));
        self::assertSame($x, $merged->classConstant('B', 'X'));
        self::assertSame($g, $merged->globalConstant('G'));
    }
}
