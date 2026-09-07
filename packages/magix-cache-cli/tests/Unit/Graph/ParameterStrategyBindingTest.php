<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\ParameterConfiguration;
use Magix\Cache\Cli\Declaration\ParameterReference;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use Magix\Cache\Cli\Graph\ParameterStrategyBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterStrategyBinding::class)]
#[UsesNamespace('Magix\Cache')]
final class ParameterStrategyBindingTest extends TestCase
{
    public function testBindUsesTheInvocationValueInsteadOfTheFactoryDefault(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('ttl', configuration: new ParameterConfiguration(strategyArgument: 'min')),
        ]);
        $declaration = new StrategyDeclaration('Strategy', createParameters: [new StrategyParameter('min', 0, true, 30)]);
        [$use, $problems] = (new ParameterStrategyBinding())->bind($boundary, new UseStrategyDeclaration('Strategy'), $declaration);

        self::assertSame([], $problems);
        self::assertInstanceOf(ParameterReference::class, $use->arguments['min']);
        self::assertSame('Strategy::create(min: $ttl)', $use->label());
    }

    public function testBindReportsCollisionWithAPositionalStaticArgument(): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('ttl', configuration: new ParameterConfiguration(strategyArgument: 'min')),
        ]);
        $declaration = new StrategyDeclaration('Strategy', createParameters: [new StrategyParameter('min', 0)]);
        [$use, $problems] = (new ParameterStrategyBinding())->bind($boundary, new UseStrategyDeclaration('Strategy', [60]), $declaration);

        self::assertSame([60], $use->arguments);
        self::assertSame(['multiple values supply strategy argument $min'], $problems);
    }

    /**
     * @param list<StrategyParameter> $parameters
     */
    #[DataProvider('providerUnusableDestinations')]
    public function testBindReportsMissingVariadicAndReferenceDestinations(array $parameters): void
    {
        $boundary = new BoundaryDeclaration('Query', 'fetch', 'a.php', 1, parameters: [
            new KeyParameter('ttl', configuration: new ParameterConfiguration(strategyArgument: 'min')),
        ]);
        $declaration = new StrategyDeclaration('Strategy', createParameters: $parameters);
        [$use, $problems] = (new ParameterStrategyBinding())->bind($boundary, new UseStrategyDeclaration('Strategy'), $declaration);

        self::assertSame([], $use->arguments);
        self::assertCount(1, $problems);
        self::assertStringContainsString('non-variadic value parameter $min', $problems[0]);
    }

    /**
     * @return iterable<string, array{list<StrategyParameter>}>
     */
    public static function providerUnusableDestinations(): iterable
    {
        yield 'missing' => [[]];
        yield 'variadic' => [[new StrategyParameter('min', 0, variadic: true)]];
        yield 'by reference' => [[new StrategyParameter('min', 0, byReference: true)]];
    }
}
