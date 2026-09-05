<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\TtlEstimateState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TtlEstimateState::class)]
final class TtlEstimateStateTest extends TestCase
{
    public function testStatesAreNamedForOutput(): void
    {
        self::assertSame('known', TtlEstimateState::Known->value);
        self::assertSame('unconstrained', TtlEstimateState::Unconstrained->value);
        self::assertSame('unknown', TtlEstimateState::Unknown->value);
        self::assertSame('invalid', TtlEstimateState::Invalid->value);
    }
}
