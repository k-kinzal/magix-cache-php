<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundaryDeclaration::class)]
#[UsesClass(KeyParameter::class)]
final class BoundaryDeclarationTest extends TestCase
{
    public function testIdUsesTheFullyQualifiedClassName(): void
    {
        $boundary = new BoundaryDeclaration('App\Query\ProductQuery', 'execute', 'src/ProductQuery.php', 12);

        self::assertSame('App\Query\ProductQuery::execute', $boundary->id());
    }

    public function testShortIdDropsTheNamespace(): void
    {
        $boundary = new BoundaryDeclaration('App\Query\ProductQuery', 'execute', 'src/ProductQuery.php', 12);
        $global = new BoundaryDeclaration('ProductQuery', 'execute', 'src/ProductQuery.php', 12);

        self::assertSame('ProductQuery::execute', $boundary->shortId());
        self::assertSame('ProductQuery::execute', $global->shortId());
    }

    public function testScopeReturnsTheStrictestParameterVisibility(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\Query\ProductQuery',
            method: 'execute',
            file: 'src/ProductQuery.php',
            line: 12,
            parameters: [
                new KeyParameter('productId'),
                new KeyParameter('viewerId', scope: Visibility::Private),
            ],
        );
        $shared = new BoundaryDeclaration('App\Query\ProductQuery', 'other', 'src/ProductQuery.php', 30);

        self::assertSame(Visibility::Private, $boundary->scope());
        self::assertSame(Visibility::Shared, $shared->scope());
    }
}
