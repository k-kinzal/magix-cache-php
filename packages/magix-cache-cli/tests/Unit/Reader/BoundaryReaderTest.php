<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\ConstantCatalog;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\AttributeReader;
use Magix\Cache\Cli\Reader\BoundaryReader;
use Magix\Cache\Cli\Reader\DependencyReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\ParameterReader;
use Magix\Cache\Cli\Reader\PolicyReader;
use Magix\Cache\Cli\Reader\TypeReader;
use Magix\Cache\Cli\Reader\UseStrategyReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundaryReader::class)]
#[UsesClass(ArgumentReader::class)]
#[UsesClass(AttributeReader::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(ConstantCatalog::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyReader::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(ParameterReader::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(PolicyReader::class)]
#[UsesClass(TypeReader::class)]
#[UsesClass(UseStrategyDeclaration::class)]
#[UsesClass(UseStrategyReader::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\ParameterConfigurationReader::class)]
#[UsesClass(\Magix\Cache\Cli\Declaration\MetadataFlow::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\ExpressionFlowReader::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\MetadataFlowReader::class)]
#[UsesClass(\Magix\Cache\Cli\Reader\StatementFlowReader::class)]
final class BoundaryReaderTest extends TestCase
{
    public function testEntryPointReadsCallsWithoutApplyingCacheDeclarations(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductController
            {
                #[\Magix\Cache\Attribute\Cache(ttl: 90)]
                public function show(#[\Magix\Cache\Attribute\CacheScope] int $viewerId): array
                {
                    return [$this->products->execute(1), $this->viewer->execute($viewerId)];
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $entryPoint = (new BoundaryReader())->entryPoint($method, 'ProductController', 'controller.php', [
            'products' => 'ProductQuery',
            'viewer' => 'ViewerQuery',
        ]);

        self::assertInstanceOf(BoundaryDeclaration::class, $entryPoint);
        self::assertFalse($entryPoint->isCacheBoundary);
        self::assertSame(90, $entryPoint->policy?->ttl);
        self::assertCount(1, $entryPoint->parameters);
        self::assertSame('viewerId', $entryPoint->parameters[0]->name);
        self::assertSame(\Magix\Cache\Metadata\Visibility::Private, $entryPoint->parameters[0]->scope);
        self::assertSame('ProductController::show', $entryPoint->id());
        self::assertSame(['ProductQuery', 'ViewerQuery'], array_map(static fn (DependencyCall $call): string => $call->class, $entryPoint->dependencies));
        self::assertNull((new BoundaryReader())->entryPoint(new ClassMethod('abstract', ['stmts' => null]), 'ProductController', 'controller.php', []));
    }

    public function testEntryPointRetainsConcreteLeavesForUncachedInspection(): void
    {
        $leaf = (new BoundaryReader())->entryPoint(new ClassMethod('empty'), 'ProductController', 'controller.php', []);

        self::assertInstanceOf(BoundaryDeclaration::class, $leaf);
        self::assertFalse($leaf->isCacheBoundary);
        self::assertSame([], $leaf->dependencies);
        self::assertNull($leaf->policy);
    }

    public function testReadDescribesACachedMethodWithItsAttributePolicy(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductQuery
            {
                #[\Magix\Cache\Attribute\Cache(ttl: 20, tags: ['product'], runtime: 'edge')]
                #[\Magix\Cache\Attribute\DynamicTtl(resolver: \App\RateTtlResolver::class)]
                public function execute(int $productId): \Magix\Cache\Cached
                {
                    return $this->cached(fn () => \Magix\Cache\Cached::of($productId));
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $boundary = (new BoundaryReader())->read($method, 'App\ProductQuery', 'src/ProductQuery.php', [], null);

        self::assertInstanceOf(BoundaryDeclaration::class, $boundary);
        self::assertSame('App\ProductQuery::execute', $boundary->id());
        self::assertSame(20, $boundary->policy?->ttl);
        self::assertSame(['product'], $boundary->policy->tags);
        self::assertSame('edge', $boundary->policy->runtime);
        self::assertTrue($boundary->hasDynamicTtl);
    }

    public function testReadSkipsMethodsThatDoNotCache(): void
    {
        $code = '<?php final class ProductQuery { public function execute(): int { return 1; } }';
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        self::assertNull((new BoundaryReader())->read($method, 'App\ProductQuery', 'src/ProductQuery.php', [], null));
    }

    public function testClassPolicyReadsTheAttributeOfTheDeclaringClass(): void
    {
        $code = <<<'SOURCE'
            <?php
            #[\Magix\Cache\Attribute\Cache(ttl: 90, tags: ['catalog'])]
            final class CatalogQuery
            {
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        $policy = (new BoundaryReader())->classPolicy($class);

        self::assertInstanceOf(PolicyDeclaration::class, $policy);
        self::assertSame(90, $policy->ttl);
        self::assertSame(PolicySource::ClassAttribute, $policy->source);
    }

    public function testClassDynamicTtlReadsTheClassLevelDefault(): void
    {
        $code = <<<'SOURCE'
            <?php
            #[\Magix\Cache\Attribute\DynamicTtl(resolver: \App\RateTtlResolver::class)]
            final class RateQuery
            {
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);
        $bare = new Class_('BareQuery');

        self::assertTrue((new BoundaryReader())->classDynamicTtl($class));
        self::assertFalse((new BoundaryReader())->classDynamicTtl($bare));
    }

    public function testPolicyPrefersTheMethodAttributeOverTheClassOne(): void
    {
        $reader = new BoundaryReader();
        $inherited = new PolicyDeclaration(PolicySource::ClassAttribute, 90);
        $bare = new ClassMethod(new Identifier('execute'));
        $declared = new ClassMethod(new Identifier('execute'), [
            'attrGroups' => [new AttributeGroup([new Attribute(
                new Name(\Magix\Cache\Attribute\Cache::class),
                [new Arg(new \PhpParser\Node\Scalar\Int_(15), name: new Identifier('ttl'))],
            )])],
        ]);

        $method = $reader->policy($declared, $inherited);

        self::assertInstanceOf(PolicyDeclaration::class, $method);
        self::assertSame(15, $method->ttl);
        self::assertSame(PolicySource::MethodAttribute, $method->source);
        self::assertSame($inherited, $reader->policy($bare, $inherited));
        self::assertNull($reader->policy($bare, null));
    }

    public function testDynamicTtlLetsAMethodDeclarationReplaceTheClassDefault(): void
    {
        $reader = new BoundaryReader();
        $bare = new ClassMethod(new Identifier('execute'));
        $disabled = new ClassMethod(new Identifier('execute'), [
            'attrGroups' => [new AttributeGroup([new Attribute(
                new Name(DynamicTtl::class),
                [new Arg(new ConstFetch(new Name('false')), name: new Identifier('enabled'))],
            )])],
        ]);
        $declared = new ClassMethod(new Identifier('execute'), [
            'attrGroups' => [new AttributeGroup([new Attribute(new Name(DynamicTtl::class))])],
        ]);

        self::assertTrue($reader->dynamicTtl($bare, true));
        self::assertFalse($reader->dynamicTtl($bare, false));
        self::assertFalse($reader->dynamicTtl($disabled, true));
        self::assertTrue($reader->dynamicTtl($declared, false));
    }

    public function testClassUseStrategyReadsTheClassLevelDeclaration(): void
    {
        $code = <<<'SOURCE'
            <?php
            #[\Magix\Cache\Attribute\UseStrategy(strategy: \App\ProductCacheStrategy::class, min: 30)]
            final class CatalogQuery
            {
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        $declared = (new BoundaryReader())->classUseStrategy($class);

        self::assertInstanceOf(UseStrategyDeclaration::class, $declared);
        self::assertSame('App\ProductCacheStrategy', $declared->strategy);
        self::assertSame(['min' => 30], $declared->arguments);
    }

    public function testUseStrategyLetsAMethodDeclarationReplaceTheClassDefault(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class CatalogQuery
            {
                #[\Magix\Cache\Attribute\UseStrategy(strategy: \App\ProductCacheStrategy::class, min: 60)]
                public function execute(): int
                {
                    return 1;
                }

                #[\Magix\Cache\Attribute\UseStrategy(strategy: \App\ProductCacheStrategy::class, enabled: false)]
                public function preview(): int
                {
                    return 1;
                }

                public function fallback(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $methods = (new NodeFinder())->findInstanceOf($statements, ClassMethod::class);
        self::assertCount(3, $methods);

        $reader = new BoundaryReader();
        $classDefault = new UseStrategyDeclaration('App\DefaultStrategy');

        self::assertSame(['min' => 60], $reader->useStrategy($methods[0], $classDefault)?->arguments);
        self::assertNull($reader->useStrategy($methods[1], $classDefault), 'a disabled method declaration replaces the default as a whole');
        self::assertSame($classDefault, $reader->useStrategy($methods[2], $classDefault));
    }

    public function testEnabledTreatsOnlyAnExplicitFalseAsDisabled(): void
    {
        $reader = new BoundaryReader();
        $bare = new Attribute(new Name(DynamicTtl::class));
        $disabled = new Attribute(new Name(DynamicTtl::class), [
            new Arg(new ConstFetch(new Name('false')), name: new Identifier('enabled')),
        ]);
        $positional = new Attribute(new Name(DynamicTtl::class), [
            new Arg(new \PhpParser\Node\Scalar\String_('App\RateTtlResolver')),
            new Arg(new ConstFetch(new Name('true'))),
        ]);

        self::assertTrue($reader->enabled($bare));
        self::assertFalse($reader->enabled($disabled));
        self::assertTrue($reader->enabled($positional));
    }
}
