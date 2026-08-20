<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Source;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\AttributeReader;
use Magix\Cache\Cli\Reader\BoundaryReader;
use Magix\Cache\Cli\Reader\DependencyReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\ParameterReader;
use Magix\Cache\Cli\Reader\PolicyReader;
use Magix\Cache\Cli\Reader\TypeReader;
use Magix\Cache\Cli\Source\ClassVisitor;
use Magix\Cache\Cli\Source\SourceParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\Project\ProductQuery;

#[CoversClass(SourceParser::class)]
#[UsesClass(ArgumentReader::class)]
#[UsesClass(AttributeReader::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(BoundaryReader::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(ClassVisitor::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyReader::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(ParameterReader::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(PolicyReader::class)]
#[UsesClass(TypeReader::class)]
final class SourceParserTest extends TestCase
{
    public function testParseReadsABoundaryFromARealFile(): void
    {
        $declarations = (new SourceParser())->parse(dirname(__DIR__, 2).'/Fixture/Project/ProductQuery.php');

        self::assertCount(1, $declarations);
        self::assertSame(ProductQuery::class, $declarations[0]->name);
        self::assertSame(20, $declarations[0]->boundaries[0]->policy?->ttl);
    }

    public function testParseRecordsTheDisplayPathOfEveryBoundary(): void
    {
        $declarations = (new SourceParser())->parse(
            dirname(__DIR__, 2).'/Fixture/Project/ProductQuery.php',
            'src/ProductQuery.php',
        );

        self::assertSame('src/ProductQuery.php', $declarations[0]->boundaries[0]->file);
    }
}
