<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Attribute\CacheKey;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Reader\AttributeReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\ParameterReader;
use Magix\Cache\Cli\Reader\TypeReader;
use Magix\Cache\Runtime\Metadata\Visibility;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterReader::class)]
#[UsesClass(AttributeReader::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(TypeReader::class)]
final class ParameterReaderTest extends TestCase
{
    public function testReadDescribesEveryParameterOfABoundary(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class PageQuery
            {
                public function execute(
                    int $productId,
                    #[\Magix\Cache\Attribute\CacheIgnore] string $trace = '',
                    mixed ...$rest,
                ): void {
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $parameters = (new ParameterReader())->read($method);

        self::assertCount(3, $parameters);
        self::assertSame('productId', $parameters[0]->name);
        self::assertSame('int', $parameters[0]->type);
        self::assertTrue($parameters[1]->ignored);
        self::assertTrue($parameters[1]->optional);
        self::assertFalse($parameters[0]->optional);
        self::assertTrue($parameters[2]->variadic);
    }

    public function testScopeDefaultsToPrivateForAScopedParameter(): void
    {
        $reader = new ParameterReader();
        $declared = [new AttributeGroup([new Attribute(
            new Name(CacheScope::class),
            [new Arg(new ClassConstFetch(new Name(Visibility::class), 'NoStore'))],
        )])];
        $bare = [new AttributeGroup([new Attribute(new Name(CacheScope::class))])];

        self::assertSame(Visibility::NoStore, $reader->scope($declared));
        self::assertSame(Visibility::Private, $reader->scope($bare));
        self::assertNull($reader->scope([]));
    }

    public function testReducerReturnsTheDeclaredStaticCallable(): void
    {
        $reader = new ParameterReader();
        $groups = [new AttributeGroup([new Attribute(
            new Name(CacheKey::class),
            [new Arg(new Array_([
                new ArrayItem(new ClassConstFetch(new Name('App\Reducer'), 'class')),
                new ArrayItem(new String_('parity')),
            ]))],
        )])];

        self::assertSame('App\Reducer::parity', $reader->reducer($groups));
        self::assertNull($reader->reducer([]));
    }
}
