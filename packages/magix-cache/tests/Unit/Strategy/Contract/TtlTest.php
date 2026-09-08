<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\Contract\TtlRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ttl::class)]
#[UsesClass(Arg::class)]
#[UsesClass(ConstructorArg::class)]
#[UsesClass(TtlRange::class)]
final class TtlTest extends TestCase
{
    public function testOnePositionalArgumentDeclaresAnExactLifetime(): void
    {
        $contract = new Ttl(30);

        self::assertSame([30], $contract->oneOf);
        self::assertFalse($contract->unconstrained);
    }

    public function testDeclaresMutuallyExclusiveLifetimeCandidates(): void
    {
        $range = new TtlRange(600, 900);
        $referenced = new TtlRange(new ConstructorArg('minimum'), new ConstructorArg('maximum'));
        $contract = new Ttl(30, 60, $range, $referenced);

        self::assertSame([30, 60, $range, $referenced], $contract->oneOf);
        self::assertNull($contract->min);
        self::assertNull($contract->max);
        self::assertFalse($contract->unconstrained);
    }

    public function testNamedBoundsDeclareOneRangeInEitherOrder(): void
    {
        $contract = new Ttl(max: 900, min: 600);

        self::assertSame(600, $contract->min);
        self::assertSame(900, $contract->max);
        self::assertNull($contract->oneOf);
    }

    public function testDeclaresBoundsAsValuesOrReferences(): void
    {
        $contract = new Ttl(min: new ConstructorArg('minimum'), max: 60);

        self::assertInstanceOf(ConstructorArg::class, $contract->min);
        self::assertSame('minimum', $contract->min->name);
        self::assertSame(60, $contract->max);
        self::assertFalse($contract->unconstrained);
    }

    public function testDeclaresAMethodArgumentReference(): void
    {
        $contract = new Ttl(max: new Arg('max'));

        self::assertInstanceOf(Arg::class, $contract->max);
        self::assertNull($contract->min);
    }

    public function testDeclaresAnUnconstrainedOperation(): void
    {
        $contract = new Ttl();

        self::assertTrue($contract->unconstrained);
        self::assertNull($contract->min);
        self::assertNull($contract->max);
    }
}
