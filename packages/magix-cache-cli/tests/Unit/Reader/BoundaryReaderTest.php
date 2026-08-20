<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\DependencyCall;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Reader\ArgumentReader;
use Magix\Cache\Cli\Reader\AttributeReader;
use Magix\Cache\Cli\Reader\BoundaryReader;
use Magix\Cache\Cli\Reader\DependencyReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\ParameterReader;
use Magix\Cache\Cli\Reader\PolicyReader;
use Magix\Cache\Cli\Reader\TypeReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
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
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyReader::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(ParameterReader::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(PolicyReader::class)]
#[UsesClass(TypeReader::class)]
final class BoundaryReaderTest extends TestCase
{
    public function testReadDescribesACachedMethodWithItsAttributePolicy(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductQuery
            {
                #[\Magix\Cache\Attribute\Cache(ttl: 20, tags: ['product'])]
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
        self::assertFalse($boundary->hasStrategy);
    }

    public function testReadSkipsMethodsThatDoNotCache(): void
    {
        $code = '<?php final class ProductQuery { public function execute(): int { return 1; } }';
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        self::assertNull((new BoundaryReader())->read($method, 'App\ProductQuery', 'src/ProductQuery.php', [], null));
    }

    public function testArgumentsBindPositionalAndNamedCachedArguments(): void
    {
        $call = new MethodCall(new Variable('this'), 'cached', [
            new Arg(new Variable('compute')),
            new Arg(new Variable('custom'), name: new Identifier('strategy')),
        ]);

        $arguments = (new BoundaryReader())->arguments($call);

        self::assertSame(['compute', 'strategy'], array_keys($arguments));
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

    public function testPolicyPrefersTheExplicitArgumentOverAttributes(): void
    {
        $reader = new BoundaryReader();
        $method = new ClassMethod(new Identifier('execute'));
        $explicit = new New_(new Name(\Magix\Cache\CachePolicy::class), [new Arg(new Int_(15))]);
        $inherited = new PolicyDeclaration(PolicySource::ClassAttribute, 90);

        $declared = $reader->policy(['policy' => $explicit], $method, $inherited);
        $dynamic = $reader->policy(['policy' => new Variable('policy')], $method, $inherited);
        $fallback = $reader->policy([], $method, $inherited);

        self::assertInstanceOf(PolicyDeclaration::class, $declared);
        self::assertInstanceOf(PolicyDeclaration::class, $dynamic);
        self::assertSame(15, $declared->ttl);
        self::assertSame(PolicySource::ExplicitPolicy, $declared->source);
        self::assertNull($dynamic->ttl);
        self::assertSame(PolicySource::Unresolved, $dynamic->source);
        self::assertSame($inherited, $fallback);
    }
}
