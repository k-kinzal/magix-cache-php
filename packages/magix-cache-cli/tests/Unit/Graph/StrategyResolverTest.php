<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
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
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyResolver::class)]
#[UsesNamespace('Magix\Cache')]
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
#[UsesClass(\Magix\Cache\Cli\Declaration\ExpirationContract::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ExpirationBinding::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\ExpirationEstimate::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\ExpiresAt::class)]
final class StrategyResolverTest extends TestCase
{
    public function testAssumedPreservesAlternativeFactoryBindings(): void
    {
        $assumption = new TtlAssumption('External', oneOf: [
            new TtlContract(30, 30),
            new TtlContract(new ContractReference(ContractSource::Create, 'minimum'), 900),
        ]);
        [$step, $problems, $adds] = (new StrategyResolver(new Catalog([])))->assumed('External', $assumption, ['minimum' => 600]);

        self::assertSame('30/600-900s', $step->ttl->label());
        self::assertSame([], $problems);
        self::assertTrue($step->assumed);
        self::assertTrue($adds);
    }

    public function testResolveOutermostOverridePreservesItsAlternatives(): void
    {
        $first = new ClassDeclaration('First', strategy: new StrategyDeclaration('First', ttl: new TtlContract(oneOf: [new TtlContract(30, 30), new TtlContract(600, 900)])));
        $second = new ClassDeclaration('Second', strategy: new StrategyDeclaration('Second', ttl: new TtlContract(oneOf: [new TtlContract(60, 60), new TtlContract(700, 800)])));
        $composed = new ClassDeclaration('Composed', strategy: new StrategyDeclaration('Composed', hasCreate: true, composed: [new StrategyInstantiation('First'), new StrategyInstantiation('Second')]));
        $boundary = new BoundaryDeclaration('Page', 'fetch', 'page.php', 1, useStrategy: new UseStrategyDeclaration('Composed'));

        $effect = (new StrategyResolver(new Catalog([$first, $second, $composed])))->resolve($boundary);

        self::assertNotNull($effect);
        self::assertSame('30/600-900s', $effect->ttl->label());
        self::assertSame([], $effect->problems);
        self::assertTrue($effect->overridesExpiration);
    }

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
        self::assertTrue($effect->overridesExpiration);
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
        self::assertTrue($effect->overridesExpiration);
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

    public function testStepTreatsAnOmittedLifetimeContractAsAddingNothing(): void
    {
        $bare = new ClassDeclaration('App\Passthrough', strategy: new StrategyDeclaration('App\Passthrough'));
        $resolver = new StrategyResolver(new Catalog([$bare]));

        [$step, $problems, $adds] = $resolver->step(
            new StrategyDeclaration('App\Composite'),
            new StrategyInstantiation('App\Passthrough'),
            [],
        );

        self::assertSame(TtlEstimateState::Unconstrained, $step->ttl->state);
        self::assertNull($step->ttl->reason);
        self::assertSame([], $problems);
        self::assertFalse($adds);
    }

    public function testCompositionPreservesAlternativesThroughAnUnannotatedChild(): void
    {
        $timed = new ClassDeclaration('App\Timed', strategy: new StrategyDeclaration(
            'App\Timed',
            ttl: new TtlContract(oneOf: [new TtlContract(30, 30), new TtlContract(600, 900)]),
        ));
        $bare = new ClassDeclaration('App\Passthrough', strategy: new StrategyDeclaration('App\Passthrough'));
        $resolver = new StrategyResolver(new Catalog([$timed, $bare]));
        $declaration = new StrategyDeclaration('App\Composite', composed: [
            new StrategyInstantiation('App\Timed'),
            new StrategyInstantiation('App\Passthrough'),
        ]);

        $effect = $resolver->composition($declaration, []);

        self::assertSame('30/600-900s', $effect->ttl->label());
        self::assertTrue($effect->overridesExpiration);
        self::assertSame([], $effect->problems);
    }

    public function testOmittingAContractStillValidatesTheConstruction(): void
    {
        $bare = new ClassDeclaration('App\Passthrough', strategy: new StrategyDeclaration(
            'App\Passthrough',
            parameters: [new StrategyParameter('required', 0)],
        ));
        $resolver = new StrategyResolver(new Catalog([$bare]));

        [$step, $problems] = $resolver->step(
            new StrategyDeclaration('App\Composite'),
            new StrategyInstantiation('App\Passthrough'),
            [],
        );

        self::assertSame(TtlEstimateState::Invalid, $step->ttl->state);
        self::assertSame(['the constructor of Passthrough is missing a value for $required'], $problems);
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
    public function testResolveMarksAnExecutableFactoryResultInvalid(): void
    {
        $declaration = new StrategyDeclaration('App\Factory', hasCreate: true, definitionProblem: 'create() must return StrategyDefinition');
        $catalog = new Catalog([new ClassDeclaration('App\Factory', strategy: $declaration)]);
        $boundary = new BoundaryDeclaration('App\Q', 'execute', 'a.php', 1, useStrategy: new UseStrategyDeclaration('App\Factory'));

        $result = (new StrategyResolver($catalog))->resolve($boundary);

        self::assertNotNull($result);
        self::assertSame(TtlEstimateState::Invalid, $result->ttl->state);
        self::assertSame(['create() must return StrategyDefinition'], $result->problems);
    }

    public function testStepPropagatesInvalidConfigurationDespiteAnAssumption(): void
    {
        $declaration = new StrategyDeclaration('App\Factory', assumptions: [new TtlAssumption('App\Child', unconstrained: true)]);
        $child = new StrategyInstantiation('App\Child', problem: 'configuration contains a closure');

        [$step, $problems] = (new StrategyResolver(new Catalog([])))->step($declaration, $child, []);

        self::assertSame(TtlEstimateState::Invalid, $step->ttl->state);
        self::assertSame(['configuration contains a closure'], $problems);
    }

    public function testStepRejectsAFactoryUsedAsAnExecutableLeaf(): void
    {
        $child = new StrategyDeclaration('App\Factory', constructible: false);
        $catalog = new Catalog([new ClassDeclaration('App\Factory', strategy: $child)]);
        [$step, $problems] = (new StrategyResolver($catalog))->step(new StrategyDeclaration('App\Outer'), new StrategyInstantiation('App\Factory'), []);

        self::assertSame(TtlEstimateState::Invalid, $step->ttl->state);
        self::assertSame(['App\Factory is not a constructible CacheStrategy for StrategyDefinition::of()'], $problems);
    }

    public function testResolvePropagatesInvocationArgumentsThroughChildContracts(): void
    {
        $composite = new ClassDeclaration('App\Composite', strategy: new StrategyDeclaration(
            name: 'App\Composite',
            createParameters: [new StrategyParameter('min', 0, true, 30)],
            hasCreate: true,
            composed: [new StrategyInstantiation(KeySpreadExpirationStrategy::class, [
                new StrategyArgument('minimum', Unresolved::Value, 'min'),
                new StrategyArgument('maximum', 60),
            ])],
        ));
        $boundary = new BoundaryDeclaration(
            'Query',
            'fetch',
            'a.php',
            1,
            parameters: [new KeyParameter('ttl', 'int', configuration: new ParameterConfiguration(strategyArgument: 'min'))],
            useStrategy: new UseStrategyDeclaration('App\Composite'),
        );
        $effect = (new StrategyResolver(new Catalog([$composite])))->resolve($boundary);

        self::assertNotNull($effect);
        self::assertSame('Composite::create(min: $ttl)', $effect->label);
        self::assertSame(TtlEstimateState::Unknown, $effect->ttl->state);
        self::assertNull($effect->ttl->lowerBound, 'the factory default is not the invocation value');
        self::assertSame(60, $effect->ttl->upperBound);
        self::assertSame([], $effect->problems);
    }

    public function testContractedMeetsClockAndTtlContractsAndReportsInvalidClocks(): void
    {
        $resolver = new StrategyResolver(new Catalog([]));
        $child = new StrategyDeclaration('App\Daily', ttl: new TtlContract(oneOf: [new TtlContract(30, 30), new TtlContract(600, 900)]), expirations: [new \Magix\Cache\Cli\Declaration\ExpirationContract('12:00', '12:15', 'Asia/Tokyo')]);
        [$step, $problems, $adds] = $resolver->contracted($child, new StrategyInstantiation('App\Daily'), []);

        self::assertSame('≤900s', $step->ttl->label(), 'an absolute expiration may shorten either duration alternative');
        self::assertTrue($step->ttl->hasFiniteExpiration());
        self::assertTrue($adds);
        self::assertSame([], $problems);
        self::assertSame('daily 12:00-12:15 Asia/Tokyo', $step->expirations[0]->label());

        $invalid = new StrategyDeclaration('App\Invalid', expirations: [new \Magix\Cache\Cli\Declaration\ExpirationContract('24:00')]);
        [$invalidStep, $invalidProblems] = $resolver->contracted($invalid, new StrategyInstantiation('App\Invalid'), []);
        self::assertSame(TtlEstimateState::Invalid, $invalidStep->ttl->state);
        self::assertSame(['#[ExpiresAt] on Invalid::fetch(): at must be a valid HH:MM or HH:MM:SS time'], $invalidProblems);
    }

    public function testStepPreservesAnExpirationContractDespiteAnAssumeTtlForUnannotatedChildren(): void
    {
        $child = new ClassDeclaration('App\Daily', strategy: new StrategyDeclaration('App\Daily', expirations: [new \Magix\Cache\Cli\Declaration\ExpirationContract('12:00')]));
        $resolver = new StrategyResolver(new Catalog([$child]));
        $parent = new StrategyDeclaration('App\Parent', assumptions: [new TtlAssumption('App\Daily', min: 60, max: 60)]);
        [$step, $problems, $adds] = $resolver->step($parent, new StrategyInstantiation('App\Daily'), []);

        self::assertFalse($step->assumed);
        self::assertSame(TtlEstimateState::Unknown, $step->ttl->state);
        self::assertTrue($adds);
        self::assertSame([], $problems);
        self::assertSame('daily 12:00 UTC', $step->expirations[0]->label());
    }

    public function testStepBindsEveryRepeatedReflectedClockContract(): void
    {
        $class = \Tests\Package\Cli\Fixture\Expiration\MultipleExpirationStrategy::class;
        $resolver = new StrategyResolver(new Catalog([]));
        [$step, $problems, $adds] = $resolver->step(new StrategyDeclaration('Parent'), new StrategyInstantiation($class, [
            new StrategyArgument('at', '18:00'),
            new StrategyArgument('until', null),
            new StrategyArgument('timezone', 'Asia/Tokyo'),
        ]), []);

        self::assertSame([], $problems);
        self::assertTrue($adds);
        self::assertTrue($step->ttl->hasFiniteExpiration());
        self::assertNull($step->ttl->upperBound);
        self::assertEquals([
            new \Magix\Cache\Cli\Graph\ExpirationEstimate('09:00', timezone: 'Asia/Tokyo'),
            new \Magix\Cache\Cli\Graph\ExpirationEstimate('23:55:30', '00:10:15', 'America/New_York', window: true),
            new \Magix\Cache\Cli\Graph\ExpirationEstimate('18:00', timezone: 'Asia/Tokyo'),
        ], $step->expirations);
    }

    public function testContractedReportsProblemsInEveryRepeatedClockDeclaration(): void
    {
        $child = new StrategyDeclaration('App\Daily', expirations: [
            new \Magix\Cache\Cli\Declaration\ExpirationContract('12:00'),
            new \Magix\Cache\Cli\Declaration\ExpirationContract(new ContractReference(ContractSource::Constructor, 'missing')),
            new \Magix\Cache\Cli\Declaration\ExpirationContract('24:00', timezone: 'No/Such_Zone'),
        ]);
        [$step, $problems] = (new StrategyResolver(new Catalog([])))->contracted($child, new StrategyInstantiation('App\Daily'), []);

        self::assertSame(TtlEstimateState::Invalid, $step->ttl->state);
        self::assertSame([
            "#[ExpiresAt] on Daily::fetch(): references ConstructorArg('missing'), but no such parameter is declared",
            '#[ExpiresAt] on Daily::fetch(): at must be a valid HH:MM or HH:MM:SS time',
            '#[ExpiresAt] on Daily::fetch(): timezone must be an IANA timezone identifier',
        ], $problems);
        self::assertSame([], $step->expirations);
    }
    public function testCompositionKnownOuterOverrideReplacesOpaqueInnerExpiration(): void
    {
        $known = new ClassDeclaration('Known', strategy: new StrategyDeclaration('Known', ttl: new TtlContract(60, 60)));
        $resolver = new StrategyResolver(new Catalog([$known]));
        $outerKnown = $resolver->composition(new StrategyDeclaration('Composition', composed: [new StrategyInstantiation('Known'), new StrategyInstantiation('Missing')]), []);
        $outerOpaque = $resolver->composition(new StrategyDeclaration('Composition', composed: [new StrategyInstantiation('Missing'), new StrategyInstantiation('Known')]), []);

        self::assertSame(60, $outerKnown->ttl->seconds);
        self::assertTrue($outerKnown->overridesExpiration);
        self::assertSame(TtlEstimateState::Unknown, $outerOpaque->ttl->state);
        self::assertNull($outerOpaque->ttl->upperBound);
        self::assertNull($outerOpaque->overridesExpiration);
    }
}
