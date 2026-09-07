<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\StrategyArgument;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyInstantiation;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use Magix\Cache\Cli\Graph\ContractBinding;
use Magix\Cache\Cli\Graph\ReflectedStrategies;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\StrategyResolver;
use Magix\Cache\Cli\Graph\StrategyStep;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyResolver::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(ConstructorArg::class)]
#[UsesClass(ContractBinding::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(ReflectedStrategies::class)]
#[UsesClass(StrategyArgument::class)]
#[UsesClass(StrategyDeclaration::class)]
#[UsesClass(StrategyEffect::class)]
#[UsesClass(StrategyInstantiation::class)]
#[UsesClass(StrategyParameter::class)]
#[UsesClass(StrategyStep::class)]
#[UsesClass(Ttl::class)]
#[UsesClass(TtlAssumption::class)]
#[UsesClass(TtlContract::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(UseStrategyDeclaration::class)]
final class StrategyResolverTest extends TestCase
{
    public function testResolveReturnsNullWithoutADeclaredStrategy(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $boundary = new BoundaryDeclaration('App\PageQuery', 'execute', 'a.php', 1);

        self::assertNull($resolver->resolve($boundary));
    }

    public function testResolveDerivesTheComposedContractOfADeclaredStrategy(): void
    {
        $leaf = new ClassDeclaration('App\Spread', strategy: new StrategyDeclaration(
            name: 'App\Spread',
            parameters: [new StrategyParameter('minimum', 0), new StrategyParameter('maximum', 1)],
            ttl: new TtlContract(
                min: new ContractReference(ContractSource::Constructor, 'minimum'),
                max: new ContractReference(ContractSource::Constructor, 'maximum'),
            ),
        ));
        $composite = new ClassDeclaration('App\Composite', strategy: new StrategyDeclaration(
            name: 'App\Composite',
            createParameters: [new StrategyParameter('min', 0, true, 30)],
            hasCreate: true,
            composed: [new StrategyInstantiation('App\Spread', [
                new StrategyArgument('minimum', Unresolved::Value, 'min'),
                new StrategyArgument('maximum', 60),
            ])],
        ));
        $resolver = new StrategyResolver(new Catalog([$leaf, $composite]));
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            useStrategy: new UseStrategyDeclaration('App\Composite', ['min' => 45]),
        );

        $effect = $resolver->resolve($boundary);

        self::assertInstanceOf(StrategyEffect::class, $effect);
        self::assertSame('Composite::create(min: 45)', $effect->label);
        self::assertSame('45-60s', $effect->ttl->label());
        self::assertTrue($effect->addsConstraint);
        self::assertCount(1, $effect->steps);
        self::assertSame('App\Spread', $effect->steps[0]->strategy);
        self::assertSame([], $effect->problems);
    }

    public function testResolveReportsAStrategyThatWasNotFound(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            useStrategy: new UseStrategyDeclaration('App\Nope'),
        );

        $effect = $resolver->resolve($boundary);

        self::assertInstanceOf(StrategyEffect::class, $effect);
        self::assertSame('Nope::create()', $effect->label);
        self::assertSame(TtlEstimateState::Unknown, $effect->ttl->state);
        self::assertSame('App\Nope was not found in the scanned sources', $effect->ttl->reason);
    }

    public function testResolveRejectsAStrategyWithoutACreateMethod(): void
    {
        $leaf = new ClassDeclaration('App\Spread', strategy: new StrategyDeclaration('App\Spread'));
        $resolver = new StrategyResolver(new Catalog([$leaf]));
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'a.php',
            line: 1,
            useStrategy: new UseStrategyDeclaration('App\Spread'),
        );

        $effect = $resolver->resolve($boundary);

        self::assertInstanceOf(StrategyEffect::class, $effect);
        self::assertSame(TtlEstimateState::Invalid, $effect->ttl->state);
        self::assertSame(['App\Spread declares no public static create(), so resolving the strategy throws a LogicException'], $effect->problems);
    }

    public function testDeclarationPrefersTheCatalogAndFallsBackToReflection(): void
    {
        $parsed = new StrategyDeclaration('App\Spread');
        $resolver = new StrategyResolver(new Catalog([new ClassDeclaration('App\Spread', strategy: $parsed)]));

        $reflected = $resolver->declaration(KeySpreadExpirationStrategy::class);

        self::assertSame($parsed, $resolver->declaration('App\Spread'));
        self::assertInstanceOf(StrategyDeclaration::class, $reflected);
        self::assertSame(KeySpreadExpirationStrategy::class, $reflected->name);
        self::assertNull($resolver->declaration('App\Absent'));
    }

    public function testCompositionStaysUnknownWithoutReadableChildren(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));

        $bare = $resolver->composition(new StrategyDeclaration('App\Opaque'), []);
        $noted = $resolver->composition(
            new StrategyDeclaration('App\Opaque', notes: ['the body of App\Opaque::create() is outside the scanned sources']),
            [],
        );

        self::assertSame(TtlEstimateState::Unknown, $bare->ttl->state);
        self::assertSame('the composition of Opaque cannot be read statically', $bare->ttl->reason);
        self::assertSame(TtlEstimateState::Unknown, $noted->ttl->state);
        self::assertSame('the body of App\Opaque::create() is outside the scanned sources', $noted->ttl->reason);
    }

    public function testCompositionMeetsTheComposedContributions(): void
    {
        $leaf = new ClassDeclaration('App\Spread', strategy: new StrategyDeclaration(
            name: 'App\Spread',
            parameters: [new StrategyParameter('minimum', 0), new StrategyParameter('maximum', 1)],
            ttl: new TtlContract(
                min: new ContractReference(ContractSource::Constructor, 'minimum'),
                max: new ContractReference(ContractSource::Constructor, 'maximum'),
            ),
        ));
        $declaration = new StrategyDeclaration(
            name: 'App\Composite',
            createParameters: [new StrategyParameter('min', 0, true, 30)],
            hasCreate: true,
            composed: [new StrategyInstantiation('App\Spread', [
                new StrategyArgument('minimum', Unresolved::Value, 'min'),
                new StrategyArgument('maximum', 60),
            ])],
        );
        $resolver = new StrategyResolver(new Catalog([$leaf]));

        $effect = $resolver->composition($declaration, ['min' => 45]);

        self::assertSame(TtlEstimateState::Unknown, $effect->ttl->state);
        self::assertSame(45, $effect->ttl->lowerBound);
        self::assertSame(60, $effect->ttl->upperBound);
        self::assertTrue($effect->addsConstraint);
        self::assertCount(1, $effect->steps);
        self::assertSame([], $effect->problems);
    }

    public function testStepCannotAnalyzeAnUnknownCreate(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $instantiation = new StrategyInstantiation('App\Ghost', [], viaCreate: true);

        [$step, $problems, $adds] = $resolver->step(new StrategyDeclaration('App\Composite'), $instantiation, []);

        self::assertSame('App\Ghost', $step->strategy);
        self::assertSame(TtlEstimateState::Unknown, $step->ttl->state);
        self::assertSame('Ghost::create() cannot be analyzed statically', $step->ttl->reason);
        self::assertSame([], $problems);
        self::assertNull($adds);
    }

    public function testStepReportsAChildWithoutALifetimeContract(): void
    {
        $bare = new ClassDeclaration('App\Passthrough', strategy: new StrategyDeclaration('App\Passthrough'));
        $resolver = new StrategyResolver(new Catalog([$bare]));

        [$step, $problems, $adds] = $resolver->step(
            new StrategyDeclaration('App\Composite'),
            new StrategyInstantiation('App\Passthrough'),
            [],
        );

        self::assertSame(TtlEstimateState::Unknown, $step->ttl->state);
        self::assertSame('Passthrough declares no lifetime contract on fetch()', $step->ttl->reason);
        self::assertSame([], $problems);
        self::assertNull($adds);
    }

    public function testStepAppliesTheDeclaredAssumption(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $declaration = new StrategyDeclaration(
            name: 'App\Composite',
            assumptions: [new TtlAssumption('App\Ghost', min: 10, max: 20)],
        );

        [$step, $problems, $adds] = $resolver->step($declaration, new StrategyInstantiation('App\Ghost', [], viaCreate: true), []);

        self::assertTrue($step->assumed);
        self::assertSame(10, $step->ttl->lowerBound);
        self::assertSame(20, $step->ttl->upperBound);
        self::assertSame([], $problems);
        self::assertTrue($adds);
    }

    public function testCreatedFollowsANestedComposition(): void
    {
        $leaf = new ClassDeclaration('App\Spread', strategy: new StrategyDeclaration(
            name: 'App\Spread',
            parameters: [new StrategyParameter('minimum', 0), new StrategyParameter('maximum', 1)],
            ttl: new TtlContract(
                min: new ContractReference(ContractSource::Constructor, 'minimum'),
                max: new ContractReference(ContractSource::Constructor, 'maximum'),
            ),
        ));
        $inner = new StrategyDeclaration(
            name: 'App\Inner',
            createParameters: [new StrategyParameter('min', 0, true, 30)],
            hasCreate: true,
            composed: [new StrategyInstantiation('App\Spread', [
                new StrategyArgument('minimum', Unresolved::Value, 'min'),
                new StrategyArgument('maximum', 60),
            ])],
        );
        $resolver = new StrategyResolver(new Catalog([$leaf, new ClassDeclaration('App\Inner', strategy: $inner)]));
        $instantiation = new StrategyInstantiation('App\Inner', [
            new StrategyArgument('min', Unresolved::Value, 'min'),
        ], viaCreate: true);

        [$step, $problems, $adds] = $resolver->created($inner, $instantiation, ['min' => 45]);

        self::assertSame('App\Inner', $step->strategy);
        self::assertSame('45-60s', $step->ttl->label());
        self::assertSame([], $problems);
        self::assertTrue($adds);
    }

    public function testContractedTreatsAnUnconstrainedContractAsAddingNothing(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $child = new StrategyDeclaration('App\Stale', ttl: new TtlContract(unconstrained: true));

        [$step, $problems, $adds] = $resolver->contracted($child, new StrategyInstantiation('App\Stale'), []);

        self::assertSame(TtlEstimateState::Unconstrained, $step->ttl->state);
        self::assertSame([], $problems);
        self::assertFalse($adds);
    }

    public function testContractedBindsTheContractToTheConstructionValues(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $child = new StrategyDeclaration(
            name: 'App\Spread',
            parameters: [new StrategyParameter('minimum', 0), new StrategyParameter('maximum', 1)],
            ttl: new TtlContract(
                min: new ContractReference(ContractSource::Constructor, 'minimum'),
                max: new ContractReference(ContractSource::Constructor, 'maximum'),
            ),
        );
        $instantiation = new StrategyInstantiation('App\Spread', [
            new StrategyArgument('minimum', 45),
            new StrategyArgument('maximum', 60),
        ]);

        [$step, $problems, $adds] = $resolver->contracted($child, $instantiation, []);

        self::assertSame(45, $step->ttl->lowerBound);
        self::assertSame(60, $step->ttl->upperBound);
        self::assertSame('45-60s', $step->ttl->label());
        self::assertSame([], $problems);
        self::assertTrue($adds);
    }

    public function testContractedRejectsAReferenceToAnUndeclaredParameter(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $child = new StrategyDeclaration(
            name: 'App\Broken',
            parameters: [new StrategyParameter('maximum', 0)],
            ttl: new TtlContract(
                min: new ContractReference(ContractSource::Constructor, 'ghost'),
                max: new ContractReference(ContractSource::Constructor, 'maximum'),
            ),
        );
        $instantiation = new StrategyInstantiation('App\Broken', [new StrategyArgument('maximum', 60)]);

        [$step, $problems, $adds] = $resolver->contracted($child, $instantiation, []);

        self::assertSame(TtlEstimateState::Invalid, $step->ttl->state);
        self::assertSame(["#[Ttl] on Broken::fetch() references ConstructorArg('ghost'), but no such parameter is declared"], $problems);
        self::assertTrue($adds);
    }

    public function testAssumedTreatsAnUnconstrainedAssumptionAsAddingNothing(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));

        [$step, $problems, $adds] = $resolver->assumed('App\Ghost', new TtlAssumption('App\Ghost', unconstrained: true), []);

        self::assertSame(TtlEstimateState::Unconstrained, $step->ttl->state);
        self::assertTrue($step->assumed);
        self::assertSame([], $problems);
        self::assertFalse($adds);
    }

    public function testAssumedResolvesCreateReferencesAgainstTheEnvironment(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $assumption = new TtlAssumption('App\Ghost', min: new ContractReference(ContractSource::Create, 'min'), max: 300);

        [$step, $problems, $adds] = $resolver->assumed('App\Ghost', $assumption, ['min' => 45]);

        self::assertSame(45, $step->ttl->lowerBound);
        self::assertSame(300, $step->ttl->upperBound);
        self::assertTrue($step->assumed);
        self::assertSame([], $problems);
        self::assertTrue($adds);
    }

    public function testAssumedRejectsAReferenceToAnUndeclaredCreateParameter(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $assumption = new TtlAssumption('App\Ghost', min: new ContractReference(ContractSource::Create, 'min'), max: 300);

        [$step, $problems, $adds] = $resolver->assumed('App\Ghost', $assumption, []);

        self::assertSame(TtlEstimateState::Invalid, $step->ttl->state);
        self::assertSame(["#[AssumeTtl] for Ghost references Arg('min'), but no such parameter is declared"], $problems);
        self::assertTrue($adds);
    }

    public function testShortNameStripsTheNamespace(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));

        self::assertSame('Spread', $resolver->shortName('App\Spread'));
        self::assertSame('Spread', $resolver->shortName('Spread'));
    }
}
