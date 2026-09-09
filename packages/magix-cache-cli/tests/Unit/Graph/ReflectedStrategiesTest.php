<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\StrategyDeclaration;
use Magix\Cache\Cli\Declaration\StrategyParameter;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Graph\ReflectedStrategies;
use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\KeySpreadExpirationStrategy;
use Magix\Cache\Strategy\StaleIfErrorCacheStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Fixture\ProductCacheStrategy;

#[CoversClass(ReflectedStrategies::class)]
#[UsesClass(Arg::class)]
#[UsesClass(ConstructorArg::class)]
#[UsesClass(ContractReference::class)]
#[UsesClass(StrategyDeclaration::class)]
#[UsesClass(StrategyParameter::class)]
#[UsesClass(Ttl::class)]
#[UsesClass(TtlContract::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\TtlRange::class)]
#[UsesClass(\Magix\Cache\Cli\Declaration\ExpirationContract::class)]
final class ReflectedStrategiesTest extends TestCase
{
    public function testAlternativesReadsRangesWithoutExecutingTheStrategy(): void
    {
        $declaration = (new ReflectedStrategies())->read(\Tests\Package\Cli\Fixture\TtlAlternatives\ConditionalTtlStrategy::class);

        self::assertNotNull($declaration);
        self::assertNotNull($declaration->ttl);
        self::assertNotNull($declaration->ttl->oneOf);
        self::assertCount(2, $declaration->ttl->oneOf);
        self::assertInstanceOf(ContractReference::class, $declaration->ttl->oneOf[0]->min);
        self::assertSame('normal', $declaration->ttl->oneOf[0]->min->name);
        self::assertInstanceOf(ContractReference::class, $declaration->ttl->oneOf[1]->max);
        self::assertSame('maximum', $declaration->ttl->oneOf[1]->max->name);
    }

    public function testReadReflectsTheConstructorParametersOfABundledLeaf(): void
    {
        $declaration = (new ReflectedStrategies())->read(KeySpreadExpirationStrategy::class);

        self::assertInstanceOf(StrategyDeclaration::class, $declaration);
        self::assertSame(KeySpreadExpirationStrategy::class, $declaration->name);
        self::assertCount(2, $declaration->parameters);
        self::assertSame('minimum', $declaration->parameters[0]->name);
        self::assertSame(0, $declaration->parameters[0]->position);
        self::assertFalse($declaration->parameters[0]->hasDefault);
        self::assertSame('maximum', $declaration->parameters[1]->name);
        self::assertSame(1, $declaration->parameters[1]->position);
        self::assertFalse($declaration->parameters[1]->hasDefault);
        self::assertFalse($declaration->hasCreate);
        self::assertNull($declaration->composed);
    }

    public function testReadReturnsNullForForeignAndUnknownClasses(): void
    {
        $reflected = new ReflectedStrategies();

        self::assertNull($reflected->read(stdClass::class));
        self::assertNull($reflected->read('App\DoesNotExist'));
    }

    public function testMethodLookupToleratesAMissingCreate(): void
    {
        $reflected = new ReflectedStrategies();

        $leaf = $reflected->read(KeySpreadExpirationStrategy::class);
        $composite = $reflected->read(ProductCacheStrategy::class);

        self::assertInstanceOf(StrategyDeclaration::class, $leaf);
        self::assertFalse($leaf->hasCreate);
        self::assertSame([], $leaf->createParameters);
        self::assertInstanceOf(StrategyDeclaration::class, $composite);
        self::assertTrue($composite->hasCreate);
        self::assertSame('min', $composite->createParameters[0]->name);
        self::assertTrue($composite->createParameters[0]->hasDefault);
        self::assertSame(30, $composite->createParameters[0]->default);
        self::assertSame(['the body of '.ProductCacheStrategy::class.'::create() is outside the scanned sources'], $composite->notes);
    }

    public function testParametersOfANullMethodAreEmpty(): void
    {
        self::assertSame([], (new ReflectedStrategies())->parameters(null));
    }

    public function testContractIsTakenFromTheFetchAttribute(): void
    {
        $declaration = (new ReflectedStrategies())->read(KeySpreadExpirationStrategy::class);

        self::assertInstanceOf(StrategyDeclaration::class, $declaration);
        $contract = $declaration->ttl;
        self::assertInstanceOf(TtlContract::class, $contract);
        $min = $contract->min;
        $max = $contract->max;
        self::assertInstanceOf(ContractReference::class, $min);
        self::assertSame(ContractSource::Constructor, $min->source);
        self::assertSame('minimum', $min->name);
        self::assertInstanceOf(ContractReference::class, $max);
        self::assertSame(ContractSource::Constructor, $max->source);
        self::assertSame('maximum', $max->name);
        self::assertFalse($contract->unconstrained);
    }

    public function testContractMayBeOmittedForAnUnconstrainedStrategy(): void
    {
        $declaration = (new ReflectedStrategies())->read(StaleIfErrorCacheStrategy::class);

        self::assertInstanceOf(StrategyDeclaration::class, $declaration);
        self::assertNull($declaration->ttl);
    }

    public function testAssumptionsAreEmptyForANullCreate(): void
    {
        self::assertSame([], (new ReflectedStrategies())->assumptions(null));
    }

    public function testBoundConvertsAttributeReferences(): void
    {
        $reflected = new ReflectedStrategies();

        $constructor = $reflected->bound(new ConstructorArg('minimum'));
        $create = $reflected->bound(new Arg('min'));

        self::assertInstanceOf(ContractReference::class, $constructor);
        self::assertSame(ContractSource::Constructor, $constructor->source);
        self::assertSame('minimum', $constructor->name);
        self::assertInstanceOf(ContractReference::class, $create);
        self::assertSame(ContractSource::Create, $create->source);
        self::assertSame('min', $create->name);
        self::assertSame(30, $reflected->bound(30));
        self::assertNull($reflected->bound(null));
    }
    public function testDefinitionProblemAcceptsConstructionDefinitionFactories(): void
    {
        $declaration = (new ReflectedStrategies())->read(ProductCacheStrategy::class);

        self::assertNotNull($declaration);
        self::assertNull($declaration->definitionProblem);
    }

    public function testExpirationReadsExternalClockContractsAndConstructorBindings(): void
    {
        $reader = new ReflectedStrategies();
        $class = \Tests\Package\Cli\Fixture\Expiration\DailyExpirationStrategy::class;
        $declaration = $reader->read($class);

        self::assertNotNull($declaration);
        self::assertCount(1, $declaration->expirations);
        $contract = $declaration->expirations[0];
        self::assertEquals(new ContractReference(ContractSource::Constructor, 'at'), $contract->at);
        self::assertEquals(new ContractReference(ContractSource::Constructor, 'until'), $contract->until);
        self::assertEquals(new ContractReference(ContractSource::Constructor, 'timezone'), $contract->timezone);
        self::assertSame('at', $declaration->parameters[0]->name);
        self::assertNull($declaration->composed, 'reflection does not execute an external factory');
        self::assertNull($reader->read(stdClass::class));
    }

    public function testExpirationsReadsEveryExternalClockContractWithoutCallingTheFactory(): void
    {
        $reader = new ReflectedStrategies();
        $declaration = $reader->read(\Tests\Package\Cli\Fixture\Expiration\MultipleExpirationStrategy::class);

        self::assertNotNull($declaration);
        self::assertEquals([
            new \Magix\Cache\Cli\Declaration\ExpirationContract('09:00', timezone: 'Asia/Tokyo'),
            new \Magix\Cache\Cli\Declaration\ExpirationContract('23:55:30', '00:10:15', 'America/New_York'),
            new \Magix\Cache\Cli\Declaration\ExpirationContract(
                new ContractReference(ContractSource::Constructor, 'at'),
                new ContractReference(ContractSource::Constructor, 'until'),
                new ContractReference(ContractSource::Constructor, 'timezone'),
            ),
        ], $declaration->expirations);
        self::assertNull($declaration->composed);
        $untimed = $reader->read(KeySpreadExpirationStrategy::class);
        self::assertNotNull($untimed);
        self::assertSame([], $untimed->expirations);
    }
}
