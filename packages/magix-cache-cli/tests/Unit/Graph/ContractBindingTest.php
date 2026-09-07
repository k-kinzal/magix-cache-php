<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\StrategyArgument;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\Unresolved;
use Magix\Cache\Cli\Graph\ContractBinding;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractBinding::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(StrategyArgument::class)]
#[UsesClass(StrategyDeclaration::class)]
#[UsesClass(StrategyParameter::class)]
#[UsesClass(TtlEstimate::class)]
final class ContractBindingTest extends TestCase
{
    public function testBindCreateBindsNamedArgumentsOverDefaults(): void
    {
        $binding = new ContractBinding();
        $declaration = new StrategyDeclaration(
            name: 'App\Composite',
            createParameters: [new StrategyParameter('min', 0, true, 30)],
            hasCreate: true,
        );

        [$named, $namedProblems] = $binding->bindCreate($declaration, ['min' => 60]);
        [$defaulted, $defaultedProblems] = $binding->bindCreate($declaration, []);

        self::assertSame(['min' => 60], $named);
        self::assertSame([], $namedProblems);
        self::assertSame(['min' => 30], $defaulted);
        self::assertSame([], $defaultedProblems);
    }

    public function testBindCreateRejectsAnUnknownArgumentName(): void
    {
        $declaration = new StrategyDeclaration(
            name: 'App\Composite',
            createParameters: [new StrategyParameter('min', 0, true, 30)],
            hasCreate: true,
        );

        [$values, $problems] = (new ContractBinding())->bindCreate($declaration, ['minimum' => 60]);

        self::assertSame(['min' => 30], $values);
        self::assertSame(['Composite::create() has no parameter $minimum, so the declared arguments cannot be bound'], $problems);
    }

    public function testBindCreateKeepsAMissingRequiredValueUnresolved(): void
    {
        $declaration = new StrategyDeclaration(
            name: 'App\Composite',
            createParameters: [new StrategyParameter('min', 0)],
            hasCreate: true,
        );

        [$values, $problems] = (new ContractBinding())->bindCreate($declaration, []);

        self::assertSame(['min' => Unresolved::Value], $values);
        self::assertSame(['Composite::create() is missing a value for $min'], $problems);
    }

    public function testBindConstructorFollowsVariablesIntoTheEnvironment(): void
    {
        $child = new StrategyDeclaration(
            name: 'App\Spread',
            parameters: [new StrategyParameter('minimum', 0), new StrategyParameter('maximum', 1)],
        );
        $arguments = [
            new StrategyArgument('minimum', Unresolved::Value, 'min'),
            new StrategyArgument('maximum', 60),
        ];

        [$values, $problems] = (new ContractBinding())->bindConstructor($child, $arguments, ['min' => 45]);

        self::assertSame(['minimum' => 45, 'maximum' => 60], $values);
        self::assertSame([], $problems);
    }

    public function testValuesFollowsVariablesAndKeepsUnknownOnesUnresolved(): void
    {
        $arguments = [
            new StrategyArgument(null, Unresolved::Value, 'min'),
            new StrategyArgument('maximum', 60),
            new StrategyArgument('accepts', null, 'judge'),
        ];

        $values = (new ContractBinding())->values($arguments, ['min' => 45]);

        self::assertSame([45, 'maximum' => 60, 'accepts' => Unresolved::Value], $values);
    }

    public function testBoundPassesPlainBoundsThrough(): void
    {
        $binding = new ContractBinding();

        self::assertSame([30, null], $binding->bound(30, ContractSource::Constructor, [], 'the subject'));
        self::assertSame([null, null], $binding->bound(null, ContractSource::Constructor, [], 'the subject'));
        self::assertSame([null, null], $binding->bound(Unresolved::Value, ContractSource::Constructor, [], 'the subject'));
    }

    public function testBoundResolvesAConstructorReference(): void
    {
        $binding = new ContractBinding();
        $reference = new ContractReference(ContractSource::Constructor, 'minimum');

        self::assertSame([45, null], $binding->bound($reference, ContractSource::Constructor, ['minimum' => 45], 'the subject'));
        self::assertSame([null, null], $binding->bound($reference, ContractSource::Constructor, ['minimum' => Unresolved::Value], 'the subject'));
    }

    public function testBoundReportsAReferenceItCannotBind(): void
    {
        $binding = new ContractBinding();

        [$foreign, $foreignProblem] = $binding->bound(new ContractReference(ContractSource::Create, 'min'), ContractSource::Constructor, ['min' => 45], 'the subject');
        [$missing, $missingProblem] = $binding->bound(new ContractReference(ContractSource::Constructor, 'minimum'), ContractSource::Constructor, [], 'the subject');

        self::assertNull($foreign);
        self::assertSame("the subject uses Arg('min'), which this declaration position cannot bind", $foreignProblem);
        self::assertNull($missing);
        self::assertSame("the subject references ConstructorArg('minimum'), but no such parameter is declared", $missingProblem);
    }

    public function testEstimateKeepsTheProvenBoundsOfARange(): void
    {
        $binding = new ContractBinding();

        $range = $binding->estimate(30, 60, 'Spread');
        $pinned = $binding->estimate(60, 60, 'Spread');

        self::assertSame(TtlEstimateState::Unknown, $range->state);
        self::assertSame(30, $range->lowerBound);
        self::assertSame(60, $range->upperBound);
        self::assertSame('30-60s', $range->label());
        self::assertSame(TtlEstimateState::Known, $pinned->state);
        self::assertSame(60, $pinned->seconds);
    }

    public function testEstimateLabelsHalfOpenRanges(): void
    {
        $binding = new ContractBinding();

        $lowerOnly = $binding->estimate(30, null, 'Spread');
        $upperOnly = $binding->estimate(null, 60, 'Spread');

        self::assertSame(30, $lowerOnly->lowerBound);
        self::assertNull($lowerOnly->upperBound);
        self::assertSame('30-?s', $lowerOnly->label());
        self::assertNull($upperOnly->lowerBound);
        self::assertSame(60, $upperOnly->upperBound);
        self::assertSame('≤60s', $upperOnly->label());
    }

    public function testEstimateReportsUnresolvedAndContradictingBounds(): void
    {
        $binding = new ContractBinding();

        $unresolved = $binding->estimate(null, null, 'Spread');
        $contradiction = $binding->estimate(60, 30, 'Spread');

        self::assertSame(TtlEstimateState::Unknown, $unresolved->state);
        self::assertSame('the declared lifetime bounds of Spread are not statically resolved', $unresolved->reason);
        self::assertSame(TtlEstimateState::Invalid, $contradiction->state);
        self::assertSame('the resolved lifetime bounds of Spread contradict (60s > 30s)', $contradiction->reason);
    }

    public function testBindMapsPositionalArgumentsToDeclaredPositions(): void
    {
        $parameters = [new StrategyParameter('minimum', 0), new StrategyParameter('maximum', 1)];

        [$values, $problems] = (new ContractBinding())->bind($parameters, [30, 60], 'the constructor of Spread');

        self::assertSame(['minimum' => 30, 'maximum' => 60], $values);
        self::assertSame([], $problems);
    }
}
