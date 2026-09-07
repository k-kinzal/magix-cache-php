<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Contract;

use Magix\Cache\Strategy\Contract\Arg;
use Magix\Cache\Strategy\Contract\AssumeTtl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssumeTtl::class)]
#[UsesClass(Arg::class)]
final class AssumeTtlTest extends TestCase
{
    public function testCoversOneStrategyWithBoundsOrReferences(): void
    {
        $assumption = new AssumeTtl(strategy: 'App\\ExternalStrategy', min: new Arg('min'), max: 300);

        self::assertSame('App\\ExternalStrategy', $assumption->strategy);
        self::assertInstanceOf(Arg::class, $assumption->min);
        self::assertSame('min', $assumption->min->name);
        self::assertSame(300, $assumption->max);
        self::assertFalse($assumption->unconstrained);
    }

    public function testAssumesAnUnconstrainedStrategy(): void
    {
        $assumption = new AssumeTtl(strategy: 'App\\ExternalStrategy', unconstrained: true);

        self::assertTrue($assumption->unconstrained);
        self::assertNull($assumption->min);
        self::assertNull($assumption->max);
    }
}
