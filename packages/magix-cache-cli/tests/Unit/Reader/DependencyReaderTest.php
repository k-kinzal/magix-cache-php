<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Reader\DependencyReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyReader::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(KeyParameter::class)]
final class DependencyReaderTest extends TestCase
{
    public function testReadCollectsCallsMadeThroughTypedProperties(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class PageQuery
            {
                public function execute(int $productId): void
                {
                    $this->cached(function () use ($productId) {
                        return $this->products->execute($productId);
                    });
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $calls = (new DependencyReader())->read(
            $method,
            'App\PageQuery',
            ['products' => 'App\ProductQuery'],
            [new KeyParameter('productId')],
        );

        self::assertCount(2, $calls);
        self::assertSame('App\PageQuery', $calls[0]->class);
        self::assertSame('cached', $calls[0]->method);
        self::assertSame('App\ProductQuery', $calls[1]->class);
        self::assertSame(['productId'], array_values($calls[1]->forwarded));
    }

    public function testVariableTypesFollowAssignmentsFromPropertiesAndConstructors(): void
    {
        $statements = [
            new Expression(new Assign(new Variable('query'), new PropertyFetch(new Variable('this'), 'products'))),
            new Expression(new Assign(new Variable('fresh'), new New_(new Name('App\FeedQuery')))),
            new Expression(new Assign(new Variable('other'), new Variable('unknown'))),
        ];

        $types = (new DependencyReader())->variableTypes($statements, ['products' => 'App\ProductQuery']);

        self::assertSame(['query' => 'App\ProductQuery', 'fresh' => 'App\FeedQuery'], $types);
    }

    public function testTargetResolvesReceiversItCanIdentify(): void
    {
        $reader = new DependencyReader();
        $property = new MethodCall(new PropertyFetch(new Variable('this'), 'products'), 'execute');
        $local = new MethodCall(new Variable('query'), 'execute');
        $inherited = new MethodCall(new Variable('this'), 'execute');
        $static = new StaticCall(new Name('self'), 'execute');
        $unknown = new MethodCall(new Variable('mystery'), 'execute');

        self::assertSame(['App\ProductQuery', 'execute'], $reader->target($property, 'App\PageQuery', ['products' => 'App\ProductQuery'], []));
        self::assertSame(['App\FeedQuery', 'execute'], $reader->target($local, 'App\PageQuery', [], ['query' => 'App\FeedQuery']));
        self::assertSame(['App\PageQuery', 'execute'], $reader->target($inherited, 'App\PageQuery', [], []));
        self::assertSame(['App\PageQuery', 'execute'], $reader->target($static, 'App\PageQuery', [], []));
        self::assertNull($reader->target($unknown, 'App\PageQuery', [], []));
    }

    public function testForwardedRecordsOnlyParametersThatReachTheKey(): void
    {
        $arguments = [
            new Arg(new Variable('viewerId')),
            new Arg(new Variable('trace'), name: new Identifier('trace')),
            new Arg(new MethodCall(new Variable('this'), 'viewerId')),
        ];

        $forwarded = (new DependencyReader())->forwarded($arguments, [
            new KeyParameter('viewerId'),
            new KeyParameter('trace', ignored: true),
        ]);

        self::assertSame([0 => 'viewerId'], $forwarded);
    }
}
