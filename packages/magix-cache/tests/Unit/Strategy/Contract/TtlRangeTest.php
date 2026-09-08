<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\TtlRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlRange::class)]
#[UsesClass(Arg::class)]
#[UsesClass(ConstructorArg::class)]
final class TtlRangeTest extends TestCase
{
    public function testDeclaresInclusiveAndUndeterminedBounds(): void
    {
        $range = new TtlRange(min: 600, max: 900);
        $open = new TtlRange(min: new ConstructorArg('minimum'));

        self::assertSame(600, $range->min);
        self::assertSame(900, $range->max);
        self::assertInstanceOf(ConstructorArg::class, $open->min);
        self::assertNull($open->max);
    }

    public function testAlternativesPreservesPointsReferencesAndRanges(): void
    {
        $alternatives = [30, new ConstructorArg('normal'), new Arg('fallback'), new TtlRange(600, 900)];

        self::assertSame($alternatives, TtlRange::alternatives($alternatives));
    }
}
