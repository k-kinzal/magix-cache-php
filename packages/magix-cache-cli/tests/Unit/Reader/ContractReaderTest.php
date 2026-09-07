<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Reader\ContractReader;
use Magix\Cache\Cli\Reader\LiteralReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractReader::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(TtlAssumption::class)]
#[UsesClass(TtlContract::class)]
final class ContractReaderTest extends TestCase
{
    public function testTtlReadsAReferenceBoundAndAConstantBound(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class MemoryStrategy
            {
                #[\Magix\Cache\Strategy\Contract\Ttl(min: new \Magix\Cache\Strategy\Contract\ConstructorArg('minimum'), max: 60)]
                public function fetch(): int
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

        $contract = (new ContractReader())->ttl($attribute);

        $min = $contract->min;
        self::assertInstanceOf(ContractReference::class, $min);
        self::assertSame(ContractSource::Constructor, $min->source);
        self::assertSame('minimum', $min->name);
        self::assertSame(60, $contract->max);
        self::assertFalse($contract->unconstrained);
    }

    public function testTtlReadsAnUnconstrainedDeclaration(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class PassThroughStrategy
            {
                #[\Magix\Cache\Strategy\Contract\Ttl(unconstrained: true)]
                public function fetch(): int
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

        $contract = (new ContractReader())->ttl($attribute);

        self::assertTrue($contract->unconstrained);
        self::assertNull($contract->min);
        self::assertNull($contract->max);
    }

    public function testAssumptionReadsTheStrategyAndItsBounds(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ComposedStrategy
            {
                #[\Magix\Cache\Strategy\Contract\AssumeTtl(strategy: \App\External::class, min: new \Magix\Cache\Strategy\Contract\Arg('min'), max: 300)]
                public static function create(): int
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

        $assumption = (new ContractReader())->assumption($attribute);

        self::assertInstanceOf(TtlAssumption::class, $assumption);
        self::assertSame('App\External', $assumption->strategy);
        $min = $assumption->min;
        self::assertInstanceOf(ContractReference::class, $min);
        self::assertSame(ContractSource::Create, $min->source);
        self::assertSame('min', $min->name);
        self::assertSame(300, $assumption->max);
        self::assertFalse($assumption->unconstrained);
    }

    public function testAssumptionSkipsADeclarationWithoutAReadableStrategy(): void
    {
        $code = <<<'SOURCE'
            <?php
            final class ComposedStrategy
            {
                #[\Magix\Cache\Strategy\Contract\AssumeTtl(strategy: SOME_LIMIT, min: 10)]
                public static function create(): int
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

        self::assertNull((new ContractReader())->assumption($attribute));
    }

    public function testValuesMapsNamedAndPositionalArgumentsAgainstTheNameList(): void
    {
        $code = <<<'SOURCE'
            <?php
            limits(10, 20, unconstrained: true);
            limits(1, 2, 3, 4);
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $calls = (new NodeFinder())->findInstanceOf($statements, FuncCall::class);
        self::assertCount(2, $calls);

        $reader = new ContractReader();

        self::assertSame(
            ['min' => 10, 'max' => 20, 'unconstrained' => true],
            $reader->values($calls[0]->args, ['min', 'max', 'unconstrained']),
        );
        self::assertSame(
            ['min' => 1, 'max' => 2, 'unconstrained' => 3],
            $reader->values($calls[1]->args, ['min', 'max', 'unconstrained']),
        );
    }

    public function testValueReadsLiteralsReferencesAndUnresolvedConstructions(): void
    {
        $code = <<<'SOURCE'
            <?php
            seed(5, new \App\Something('x'), new \Magix\Cache\Strategy\Contract\ConstructorArg('x'));
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $call = (new NodeFinder())->findFirstInstanceOf($statements, FuncCall::class);
        self::assertInstanceOf(FuncCall::class, $call);
        $literal = $call->args[0] ?? null;
        $construction = $call->args[1] ?? null;
        $declared = $call->args[2] ?? null;
        self::assertInstanceOf(Arg::class, $literal);
        self::assertInstanceOf(Arg::class, $construction);
        self::assertInstanceOf(Arg::class, $declared);

        $reader = new ContractReader();

        self::assertSame(5, $reader->value($literal->value));
        self::assertSame(Unresolved::Value, $reader->value($construction->value));
        $reference = $reader->value($declared->value);
        self::assertInstanceOf(ContractReference::class, $reference);
        self::assertSame(ContractSource::Constructor, $reference->source);
        self::assertSame('x', $reference->name);
    }

    public function testReferenceReadsExplicitDeclarationsAndSkipsTheRest(): void
    {
        $code = <<<'SOURCE'
            <?php
            new \Magix\Cache\Strategy\Contract\ConstructorArg('minimum');
            new \Magix\Cache\Strategy\Contract\Arg('lifetime');
            new \App\Other('minimum');
            new \Magix\Cache\Strategy\Contract\ConstructorArg(LIMIT);
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $constructions = (new NodeFinder())->findInstanceOf($statements, New_::class);
        self::assertCount(4, $constructions);

        $reader = new ContractReader();

        $constructor = $reader->reference($constructions[0]);
        self::assertInstanceOf(ContractReference::class, $constructor);
        self::assertSame(ContractSource::Constructor, $constructor->source);
        self::assertSame('minimum', $constructor->name);
        $create = $reader->reference($constructions[1]);
        self::assertInstanceOf(ContractReference::class, $create);
        self::assertSame(ContractSource::Create, $create->source);
        self::assertSame('lifetime', $create->name);
        self::assertNull($reader->reference($constructions[2]));
        self::assertNull($reader->reference($constructions[3]));
    }

    public function testBoundKeepsLifetimeShapesAndMarksTheRest(): void
    {
        $reader = new ContractReader();
        $reference = new ContractReference(ContractSource::Create, 'min');

        self::assertSame(30, $reader->bound(30));
        self::assertNull($reader->bound(null));
        self::assertSame($reference, $reader->bound($reference));
        self::assertSame(Unresolved::Value, $reader->bound(Unresolved::Value));
        self::assertSame(Unresolved::Value, $reader->bound('text'));
    }
}
