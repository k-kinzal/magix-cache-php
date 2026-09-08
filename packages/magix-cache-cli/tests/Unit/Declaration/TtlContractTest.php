<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\TtlContract;
use Magix\Cache\Cli\Declaration\Unresolved;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlContract::class)]
#[UsesClass(ContractReference::class)]
final class TtlContractTest extends TestCase
{
    public function testDeclarationProblemsDistinguishesAlternativesFromSimultaneousBounds(): void
    {
        $alternatives = [new TtlContract(min: 30, max: 30), new TtlContract(min: 600, max: 900)];

        self::assertSame([], (new TtlContract(oneOf: $alternatives))->declarationProblems());
        self::assertSame(['lifetime alternatives must be a non-empty list'], (new TtlContract(oneOf: []))->declarationProblems());
        self::assertSame(
            ['lifetime alternatives cannot be combined with bounds or unconstrained'],
            (new TtlContract(min: 30, oneOf: $alternatives))->declarationProblems(),
        );
    }

    public function testAContractKeepsItsWrittenBounds(): void
    {
        $maximum = new ContractReference(ContractSource::Constructor, 'seconds');
        $contract = new TtlContract(60, $maximum);

        self::assertSame(60, $contract->min);
        self::assertSame($maximum, $contract->max);
        self::assertFalse($contract->unconstrained);
    }

    public function testAnUnconstrainedContractAddsNoLifetimeConstraint(): void
    {
        $contract = new TtlContract(unconstrained: true);

        self::assertNull($contract->min);
        self::assertNull($contract->max);
        self::assertTrue($contract->unconstrained);
    }

    public function testAContractMayCarryAnUnresolvedBound(): void
    {
        $contract = new TtlContract(max: Unresolved::Value);

        self::assertNull($contract->min);
        self::assertSame(Unresolved::Value, $contract->max);
    }
}
