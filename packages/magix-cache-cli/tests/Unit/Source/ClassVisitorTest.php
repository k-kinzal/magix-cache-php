<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Source;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
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
use Magix\Cache\Cli\Source\ClassVisitor;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassVisitor::class)]
#[UsesClass(ArgumentReader::class)]
#[UsesClass(AttributeReader::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(BoundaryReader::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(DependencyCall::class)]
#[UsesClass(DependencyReader::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(ParameterReader::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(PolicyReader::class)]
#[UsesClass(TypeReader::class)]
final class ClassVisitorTest extends TestCase
{
    public function testEnterNodeCollectsBoundariesWithTheClassPolicy(): void
    {
        $code = <<<'SOURCE'
            <?php
            namespace App;

            #[\Magix\Cache\Attribute\Cache(ttl: 45)]
            final class PageQuery implements \App\PageContract
            {
                public function __construct(private readonly ProductQuery $products)
                {
                }

                public function execute(int $productId): \Magix\Cache\Cached
                {
                    return $this->cached(fn () => $this->products->execute($productId));
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $visitor = new ClassVisitor('src/PageQuery.php');
        (new NodeTraverser($visitor))->traverse($statements);

        $declarations = $visitor->declarations();

        self::assertCount(1, $declarations);
        self::assertSame('App\PageQuery', $declarations[0]->name);
        self::assertSame(['App\PageContract'], $declarations[0]->parents);
        self::assertSame('App\PageQuery::execute', $declarations[0]->boundaries[0]->id());
        self::assertSame(45, $declarations[0]->boundaries[0]->policy?->ttl);
        self::assertSame(PolicySource::ClassAttribute, $declarations[0]->boundaries[0]->policy->source);
        self::assertSame('App\ProductQuery', $declarations[0]->boundaries[0]->dependencies[1]->class);
    }

    public function testPropertyTypesCoverDeclaredAndPromotedProperties(): void
    {
        $code = <<<'SOURCE'
            <?php
            namespace App;

            final class PageQuery
            {
                private ProductQuery $products;
                private int $limit = 10;

                public function __construct(private readonly ViewerQuery $viewer, int $ignored)
                {
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        $types = (new ClassVisitor('src/PageQuery.php'))->propertyTypes($class);

        self::assertSame(['products' => 'App\ProductQuery', 'viewer' => 'App\ViewerQuery'], $types);
    }

    public function testDeclarationsAreEmptyForFilesWithoutClasses(): void
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php $value = 1;') ?? [];
        $visitor = new ClassVisitor('src/script.php');
        (new NodeTraverser($visitor))->traverse($statements);

        self::assertSame([], $visitor->declarations());
    }
}
