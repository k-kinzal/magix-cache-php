<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Graph\ContractBinding;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Graph\TtlInterval;
use Magix\Cache\Cli\Graph\TtlRangeSet;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractReader::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(LiteralReader::class)]
#[UsesClass(TtlAssumption::class)]
#[UsesClass(TtlContract::class)]
#[UsesClass(ContractBinding::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(TtlInterval::class)]
#[UsesClass(TtlRangeSet::class)]
final class ContractReaderTest extends TestCase
{
    #[DataProvider('providerLifetimeSyntax')]
    public function testDeclarationSyntaxResolvesToTheDeclaredCandidate(string $arguments, string $label): void
    {
        $code = '<?php use Magix\Cache\Strategy\Contract\{Ttl, TtlRange, ConstructorArg};'
            .'final class Timed { #[Ttl('.$arguments.')] public function fetch(): void {} }';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);
        $contract = (new ContractReader())->ttl($attribute);
        [$estimate, $problems] = (new ContractBinding())->contract(
            $contract,
            ContractSource::Constructor,
            ['minimum' => 1200, 'maximum' => 1500],
            'Timed',
        );

        self::assertSame($label, $estimate->label());
        self::assertSame([], $problems);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerLifetimeSyntax(): iterable
    {
        yield 'fixed' => ['30', '30s'];
        yield 'two choices' => ['30, 60', '30/60s'];
        yield 'named range' => ['min: 600, max: 900', '600-900s'];
        yield 'reordered range' => ['max: 900, min: 600', '600-900s'];
        yield 'minimum only' => ['min: 600', '600-?s'];
        yield 'maximum only' => ['max: 900', '≤900s'];
        yield 'all forms' => [
            "30, 60, new TtlRange(min: 600, max: 900), new TtlRange(new ConstructorArg('minimum'), new ConstructorArg('maximum'))",
            '30/60/600-900/1200-1500s',
        ];
        yield 'no constraint' => ['', 'unconstrained'];
    }

    #[DataProvider('providerMalformedLifetimeSyntax')]
    public function testMalformedSyntaxRetainsProblemsForLint(string $arguments): void
    {
        $code = '<?php use Magix\Cache\Strategy\Contract\{Ttl, TtlRange};'
            .'final class Timed { #[Ttl('.$arguments.')] public function fetch(): void {} }';
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);
        [$estimate, $problems] = (new ContractBinding())->contract(
            (new ContractReader())->ttl($attribute),
            ContractSource::Constructor,
            [],
            'Timed',
        );

        self::assertSame(TtlEstimateState::Invalid, $estimate->state);
        self::assertNotEmpty($problems);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providerMalformedLifetimeSyntax(): iterable
    {
        yield 'mixed forms' => ['30, min: 600, max: 900'];
        yield 'unknown name' => ['minimum: 30'];
        yield 'old oneOf' => ['oneOf: [30, 60]'];
        yield 'old unconstrained' => ['unconstrained: true'];
        yield 'array' => ['[30, 60]'];
        yield 'null point' => ['null'];
        yield 'boolean' => ['false'];
        yield 'negative' => ['30, -1'];
        yield 'range as bound' => ['min: new TtlRange(600, 900)'];
        yield 'extra range argument' => ['new TtlRange(600, 900, 1200)'];
        yield 'misspelled range bound' => ['new TtlRange(minimum: 600, max: 900)'];
        yield 'empty range' => ['new TtlRange()'];
        yield 'reversed range' => ['30, new TtlRange(900, 600)'];
    }

    public function testAssumptionReadsAlternativeFactoryReferences(): void
    {
        $code = <<<'SOURCE'
            <?php
            use Magix\Cache\Strategy\Contract\{AssumeTtl, TtlRange, Arg};
            final class Timed {
                #[AssumeTtl(External::class, 30, new TtlRange(min: new Arg('minimum'), max: 900))]
                public static function create(): void {}
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);
        $assumption = (new ContractReader())->assumption($attribute);

        self::assertNotNull($assumption);
        self::assertSame('External', $assumption->strategy);
        self::assertNotNull($assumption->oneOf);
        self::assertInstanceOf(ContractReference::class, $assumption->oneOf[1]->min);
        self::assertSame(ContractSource::Create, $assumption->oneOf[1]->min->source);
        self::assertSame('minimum', $assumption->oneOf[1]->min->name);
    }

    public function testAssumptionRetainsInvalidNamedOptionsForLint(): void
    {
        $code = '<?php final class Factory {'
            .' #[\Magix\Cache\Strategy\Contract\AssumeTtl("External", 30, min: 600)]'
            .' public static function create(): void {} }';
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);
        $assumption = (new ContractReader())->assumption($attribute);

        self::assertNotNull($assumption);
        self::assertSame('External', $assumption->strategy);
        self::assertNotEmpty($assumption->contract()->declarationProblems());
    }

    public function testTtlReadsAlternativePointsRangesAndConstructorReferences(): void
    {
        $code = <<<'SOURCE'
            <?php
            use Magix\Cache\Strategy\Contract\{Ttl, TtlRange, ConstructorArg};
            final class Timed {
                #[Ttl(30, new TtlRange(min: 600, max: new ConstructorArg('maximum')))]
                public function fetch(): void {}
            }
            SOURCE;
        $statements = (new NodeTraverser(new NameResolver()))->traverse(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [],
        );
        $attribute = (new NodeFinder())->findFirstInstanceOf($statements, Attribute::class);
        self::assertInstanceOf(Attribute::class, $attribute);
        $contract = (new ContractReader())->ttl($attribute);

        self::assertNotNull($contract->oneOf);
        self::assertCount(2, $contract->oneOf);
        self::assertSame(30, $contract->oneOf[0]->min);
        self::assertSame(30, $contract->oneOf[0]->max);
        self::assertSame(600, $contract->oneOf[1]->min);
        self::assertInstanceOf(ContractReference::class, $contract->oneOf[1]->max);
        self::assertSame('maximum', $contract->oneOf[1]->max->name);
    }

    public function testAlternativesKeepsUnreadableBranchesAndMalformedEntries(): void
    {
        $reader = new ContractReader();
        $alternatives = $reader->alternatives([30, Unresolved::Value]);
        self::assertNotNull($alternatives);
        self::assertSame(Unresolved::Value, $alternatives[1]->min);
        self::assertSame([], $reader->alternatives([]));
        self::assertNull($reader->alternatives(null));
        $malformed = $reader->alternatives([false]);
        self::assertNotNull($malformed);
        self::assertNotEmpty($malformed[0]->problems);
    }

    public function testRangeReadsAReferenceBoundAndAConstantBound(): void
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
                #[\Magix\Cache\Strategy\Contract\Ttl()]
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
            ['min' => 1, 'max' => 2, 'unconstrained' => 3, 3 => 4],
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
