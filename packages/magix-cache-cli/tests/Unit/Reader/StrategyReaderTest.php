<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\StrategyArgument;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyInstantiation;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Reader\AttributeReader;
use Magix\Cache\Cli\Reader\ContractReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use Magix\Cache\Cli\Reader\StrategyReader;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyReader::class)]
#[UsesClass(AttributeReader::class)]
#[UsesClass(ContractReader::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(StrategyArgument::class)]
#[UsesClass(StrategyDeclaration::class)]
#[UsesClass(StrategyInstantiation::class)]
#[UsesClass(StrategyParameter::class)]
#[UsesClass(TtlAssumption::class)]
#[UsesClass(TtlContract::class)]
final class StrategyReaderTest extends TestCase
{
    public function testReadReadsACompositeStrategyWithItsCompositionInOrder(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ProductCacheStrategy extends \Magix\Cache\Strategy\CompositeCacheStrategy
            {
                public static function create(int $min = 30): \Magix\Cache\Strategy\CacheStrategy
                {
                    return parent::compose(
                        new \App\KeySpread(minimum: $min, maximum: 60),
                        \App\Nested::create($min),
                    );
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        $strategy = (new StrategyReader())->read($class, 'src/ProductCacheStrategy.php');

        self::assertInstanceOf(StrategyDeclaration::class, $strategy);
        self::assertSame('ProductCacheStrategy', $strategy->name);
        self::assertSame('src/ProductCacheStrategy.php', $strategy->file);
        self::assertTrue($strategy->hasCreate);
        self::assertSame([], $strategy->parameters);
        self::assertSame([], $strategy->notes);
        self::assertCount(1, $strategy->createParameters);
        $parameter = $strategy->createParameters[0];
        self::assertSame('min', $parameter->name);
        self::assertSame(0, $parameter->position);
        self::assertTrue($parameter->hasDefault);
        self::assertSame(30, $parameter->default);
        self::assertNotNull($strategy->composed);
        self::assertCount(2, $strategy->composed);
        $spread = $strategy->composed[0];
        self::assertSame('App\KeySpread', $spread->class);
        self::assertFalse($spread->viaCreate);
        self::assertCount(2, $spread->arguments);
        self::assertSame('minimum', $spread->arguments[0]->name);
        self::assertSame('min', $spread->arguments[0]->variable);
        self::assertSame(Unresolved::Value, $spread->arguments[0]->value);
        self::assertSame('maximum', $spread->arguments[1]->name);
        self::assertSame(60, $spread->arguments[1]->value);
        self::assertNull($spread->arguments[1]->variable);
        $nested = $strategy->composed[1];
        self::assertSame('App\Nested', $nested->class);
        self::assertTrue($nested->viaCreate);
        self::assertCount(1, $nested->arguments);
        self::assertNull($nested->arguments[0]->name);
        self::assertSame('min', $nested->arguments[0]->variable);
        self::assertSame(Unresolved::Value, $nested->arguments[0]->value);
    }

    public function testReadReadsALeafStrategyContractAndConstructor(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class MemoryStrategy implements \Magix\Cache\Strategy\CacheStrategy
            {
                public function __construct(private int $minimum)
                {
                }

                #[\Magix\Cache\Strategy\Contract\Ttl(min: new \Magix\Cache\Strategy\Contract\ConstructorArg('minimum'))]
                public function fetch(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        $strategy = (new StrategyReader())->read($class, 'src/MemoryStrategy.php');

        self::assertInstanceOf(StrategyDeclaration::class, $strategy);
        self::assertSame('MemoryStrategy', $strategy->name);
        self::assertFalse($strategy->hasCreate);
        self::assertNull($strategy->composed);
        self::assertSame([], $strategy->createParameters);
        self::assertCount(1, $strategy->parameters);
        self::assertSame('minimum', $strategy->parameters[0]->name);
        self::assertSame(0, $strategy->parameters[0]->position);
        self::assertFalse($strategy->parameters[0]->hasDefault);
        $ttl = $strategy->ttl;
        self::assertInstanceOf(TtlContract::class, $ttl);
        $min = $ttl->min;
        self::assertInstanceOf(ContractReference::class, $min);
        self::assertSame(ContractSource::Constructor, $min->source);
        self::assertSame('minimum', $min->name);
        self::assertNull($ttl->max);
    }

    public function testReadSkipsAClassThatDeclaresNoStrategy(): void
    {
        $code = '<?php final class Plain { public function fetch(): int { return 1; } }';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Class_::class);
        self::assertInstanceOf(Class_::class, $class);

        self::assertNull((new StrategyReader())->read($class, 'src/Plain.php'));
    }

    public function testParametersReadsDeclaredParametersInOrder(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class Sample
            {
                public function __construct(private int $minimum, string $label = 'ttl')
                {
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $reader = new StrategyReader();
        $parameters = $reader->parameters($method);

        self::assertCount(2, $parameters);
        self::assertSame('minimum', $parameters[0]->name);
        self::assertSame(0, $parameters[0]->position);
        self::assertFalse($parameters[0]->hasDefault);
        self::assertNull($parameters[0]->default);
        self::assertSame('label', $parameters[1]->name);
        self::assertSame(1, $parameters[1]->position);
        self::assertTrue($parameters[1]->hasDefault);
        self::assertSame('ttl', $parameters[1]->default);
        self::assertSame([], $reader->parameters(null));
    }

    public function testContractReadsTheTtlAttributeOnAnOperation(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class Sample
            {
                #[\Magix\Cache\Strategy\Contract\Ttl(min: 10, max: 60)]
                public function fetch(): int
                {
                    return 1;
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $contract = (new StrategyReader())->contract($method);

        self::assertInstanceOf(TtlContract::class, $contract);
        self::assertSame(10, $contract->min);
        self::assertSame(60, $contract->max);
        self::assertFalse($contract->unconstrained);
    }

    public function testContractSkipsMethodsWithoutADeclaration(): void
    {
        $code = '<?php final class Sample { public function fetch(): int { return 1; } }';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $reader = new StrategyReader();

        self::assertNull($reader->contract($method));
        self::assertNull($reader->contract(null));
    }

    public function testAssumptionsReadsEveryDeclaredAssumptionInOrder(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ComposedStrategy extends \Magix\Cache\Strategy\CompositeCacheStrategy
            {
                #[\Magix\Cache\Strategy\Contract\AssumeTtl(strategy: \App\First::class, min: 10)]
                #[\Magix\Cache\Strategy\Contract\AssumeTtl(strategy: \App\Second::class, max: 300)]
                public static function create(): \Magix\Cache\Strategy\CacheStrategy
                {
                    return parent::compose(new \App\First(), new \App\Second());
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        $assumptions = (new StrategyReader())->assumptions($method);

        self::assertCount(2, $assumptions);
        self::assertSame('App\First', $assumptions[0]->strategy);
        self::assertSame(10, $assumptions[0]->min);
        self::assertNull($assumptions[0]->max);
        self::assertSame('App\Second', $assumptions[1]->strategy);
        self::assertNull($assumptions[1]->min);
        self::assertSame(300, $assumptions[1]->max);
    }

    public function testCompositionNotesACreateThatDoesNotReturnOnce(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class BranchStrategy extends \Magix\Cache\Strategy\CompositeCacheStrategy
            {
                public static function create(bool $flag): \Magix\Cache\Strategy\CacheStrategy
                {
                    if ($flag) {
                        return parent::compose(new \App\First());
                    } else {
                        return parent::compose(new \App\Second());
                    }
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $method = (new NodeFinder())->findFirstInstanceOf($statements, ClassMethod::class);
        self::assertInstanceOf(ClassMethod::class, $method);

        [$composed, $notes] = (new StrategyReader())->composition($method);

        self::assertNull($composed);
        self::assertSame(['create() does not resolve to a single composition statically'], $notes);
    }

    public function testComposeReadsTheArgumentsOfAComposeCall(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ComposedStrategy extends \Magix\Cache\Strategy\CompositeCacheStrategy
            {
                public static function create(): \Magix\Cache\Strategy\CacheStrategy
                {
                    return parent::compose($first, $second);
                }
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $call = (new NodeFinder())->findFirstInstanceOf($statements, StaticCall::class);
        self::assertInstanceOf(StaticCall::class, $call);

        $children = (new StrategyReader())->compose($call);

        self::assertNotNull($children);
        self::assertCount(2, $children);
        $first = $children[0];
        $second = $children[1];
        self::assertInstanceOf(Variable::class, $first);
        self::assertSame('first', $first->name);
        self::assertInstanceOf(Variable::class, $second);
        self::assertSame('second', $second->name);
    }

    public function testComposeSkipsExpressionsThatAreNotAComposeCall(): void
    {
        $code = <<<'SOURCE'
            <?php
            \App\X::make($first, $second);
            new \App\Y();
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $call = (new NodeFinder())->findFirstInstanceOf($statements, StaticCall::class);
        $construction = (new NodeFinder())->findFirstInstanceOf($statements, New_::class);
        self::assertInstanceOf(StaticCall::class, $call);
        self::assertInstanceOf(New_::class, $construction);

        $reader = new StrategyReader();

        self::assertNull($reader->compose($call));
        self::assertNull($reader->compose($construction));
    }

    public function testInstantiationReadsANewExpression(): void
    {
        $code = '<?php new \App\X(1);';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $construction = (new NodeFinder())->findFirstInstanceOf($statements, New_::class);
        self::assertInstanceOf(New_::class, $construction);

        $instantiation = (new StrategyReader())->instantiation($construction);

        self::assertInstanceOf(StrategyInstantiation::class, $instantiation);
        self::assertSame('App\X', $instantiation->class);
        self::assertFalse($instantiation->viaCreate);
        self::assertCount(1, $instantiation->arguments);
        self::assertNull($instantiation->arguments[0]->name);
        self::assertSame(1, $instantiation->arguments[0]->value);
        self::assertNull($instantiation->arguments[0]->variable);
    }

    public function testInstantiationSkipsACallItCannotFollow(): void
    {
        $code = '<?php $factory->build();';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $call = (new NodeFinder())->findFirstInstanceOf($statements, MethodCall::class);
        self::assertInstanceOf(MethodCall::class, $call);

        self::assertNull((new StrategyReader())->instantiation($call));
    }

    public function testArgumentsRecordsVariablesLiteralsAndNames(): void
    {
        $code = <<<'SOURCE'
            <?php
            seed($amount, 60, label: 'hot');
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $call = (new NodeFinder())->findFirstInstanceOf($statements, FuncCall::class);
        self::assertInstanceOf(FuncCall::class, $call);

        $arguments = (new StrategyReader())->arguments($call->args);

        self::assertNotNull($arguments);
        self::assertCount(3, $arguments);
        self::assertNull($arguments[0]->name);
        self::assertSame(Unresolved::Value, $arguments[0]->value);
        self::assertSame('amount', $arguments[0]->variable);
        self::assertNull($arguments[1]->name);
        self::assertSame(60, $arguments[1]->value);
        self::assertNull($arguments[1]->variable);
        self::assertSame('label', $arguments[2]->name);
        self::assertSame('hot', $arguments[2]->value);
        self::assertNull($arguments[2]->variable);
    }

    public function testArgumentsSkipsAnUnpackedArgumentList(): void
    {
        $code = '<?php seed(...$values);';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $call = (new NodeFinder())->findFirstInstanceOf($statements, FuncCall::class);
        self::assertInstanceOf(FuncCall::class, $call);

        self::assertNull((new StrategyReader())->arguments($call->args));
    }
}
