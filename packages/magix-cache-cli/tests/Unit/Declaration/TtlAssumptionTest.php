<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\ContractReference;
use Magix\Cache\Cli\Declaration\ContractSource;
use Magix\Cache\Cli\Declaration\TtlAssumption;
use Magix\Cache\Cli\Declaration\Unresolved;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlAssumption::class)]
#[UsesClass(ContractReference::class)]
final class TtlAssumptionTest extends TestCase
{
    public function testAnAssumptionKeepsItsDeclaredBounds(): void
    {
        $minimum = new ContractReference(ContractSource::Create, 'min');
        $assumption = new TtlAssumption('App\Cache\RedisStrategy', $minimum, 300);

        self::assertSame('App\Cache\RedisStrategy', $assumption->strategy);
        self::assertSame($minimum, $assumption->min);
        self::assertSame(300, $assumption->max);
        self::assertFalse($assumption->unconstrained);
    }

    public function testAnUnconstrainedAssumptionLeavesBothBoundsUndeclared(): void
    {
        $assumption = new TtlAssumption('App\Cache\RedisStrategy', unconstrained: true);

        self::assertNull($assumption->min);
        self::assertNull($assumption->max);
        self::assertTrue($assumption->unconstrained);
    }

    public function testAnAssumptionMayCarryAnUnresolvedBound(): void
    {
        $assumption = new TtlAssumption('App\Cache\RedisStrategy', min: Unresolved::Value);

        self::assertSame(Unresolved::Value, $assumption->min);
        self::assertNull($assumption->max);
    }
}
