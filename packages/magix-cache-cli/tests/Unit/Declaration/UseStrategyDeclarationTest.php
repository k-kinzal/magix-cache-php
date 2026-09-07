<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UseStrategyDeclaration::class)]
final class UseStrategyDeclarationTest extends TestCase
{
    public function testLabelRendersTheDeclaredConstruction(): void
    {
        $declaration = new UseStrategyDeclaration(
            'App\Cache\ProductCacheStrategy',
            ['min' => 60, 'name' => 'x', 0 => Unresolved::Value, 'flag' => true],
            12,
        );

        self::assertSame('App\Cache\ProductCacheStrategy', $declaration->strategy);
        self::assertSame(12, $declaration->line);
        self::assertSame("ProductCacheStrategy::create(min: 60, name: 'x', ?, flag: true)", $declaration->label());
    }

    public function testLabelRendersAClassWithoutANamespaceAndWithoutArguments(): void
    {
        $declaration = new UseStrategyDeclaration('ProductCacheStrategy');

        self::assertSame([], $declaration->arguments);
        self::assertSame(0, $declaration->line);
        self::assertSame('ProductCacheStrategy::create()', $declaration->label());
    }
}
