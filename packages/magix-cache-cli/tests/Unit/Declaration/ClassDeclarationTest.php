<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassDeclaration::class)]
#[UsesClass(BoundaryDeclaration::class)]
final class ClassDeclarationTest extends TestCase
{
    public function testDeclarationKeepsItsParentsAndBoundaries(): void
    {
        $boundary = new BoundaryDeclaration('App\HomeQuery', 'execute', 'src/HomeQuery.php', 10);

        $declaration = new ClassDeclaration('App\HomeQuery', ['App\FeedQuery'], [$boundary]);

        self::assertSame('App\HomeQuery', $declaration->name);
        self::assertSame(['App\FeedQuery'], $declaration->parents);
        self::assertSame([$boundary], $declaration->boundaries);
    }
}
