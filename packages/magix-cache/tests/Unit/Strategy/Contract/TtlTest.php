<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ttl::class)]
final class TtlTest extends TestCase
{
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
        $contract = new Ttl(unconstrained: true);

        self::assertTrue($contract->unconstrained);
        self::assertNull($contract->min);
        self::assertNull($contract->max);
    }
}
