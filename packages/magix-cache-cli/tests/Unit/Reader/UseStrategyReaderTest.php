<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\UseStrategyReader;
use PhpParser\Node\Attribute;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UseStrategyReader::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(UseStrategyDeclaration::class)]
final class UseStrategyReaderTest extends TestCase
{
    public function testReadReadsANamedStrategyWithItsCreateArguments(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductQuery
            {
                #[\Magix\Cache\Attribute\UseStrategy(strategy: \App\ProductCacheStrategy::class, min: 60)]
                public function execute(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);

        $declaration = (new UseStrategyReader())->read($attribute);

        self::assertInstanceOf(UseStrategyDeclaration::class, $declaration);
        self::assertSame('App\ProductCacheStrategy', $declaration->strategy);
        self::assertSame(['min' => 60], $declaration->arguments);
        self::assertSame(4, $declaration->line);
    }

    public function testReadMapsPositionalArgumentsAfterStrategyAndEnabled(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductQuery
            {
                #[\Magix\Cache\Attribute\UseStrategy(\App\S::class, true, 60)]
                public function execute(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);

        $declaration = (new UseStrategyReader())->read($attribute);

        self::assertInstanceOf(UseStrategyDeclaration::class, $declaration);
        self::assertSame('App\S', $declaration->strategy);
        self::assertSame([60], $declaration->arguments);
    }

    public function testReadSkipsADisabledDeclaration(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductQuery
            {
                #[\Magix\Cache\Attribute\UseStrategy(strategy: \App\S::class, enabled: false)]
                public function execute(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);

        self::assertNull((new UseStrategyReader())->read($attribute));
    }

    public function testReadSkipsADeclarationWithoutAReadableStrategy(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductQuery
            {
                #[\Magix\Cache\Attribute\UseStrategy(strategy: SOME_CONST)]
                public function execute(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);

        self::assertNull((new UseStrategyReader())->read($attribute));
    }
}
