<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\StrategyParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyParameter::class)]
final class StrategyParameterTest extends TestCase
{
    public function testAParameterKeepsItsDeclaredDefault(): void
    {
        $parameter = new StrategyParameter('seconds', 1, true, 60);

        self::assertSame('seconds', $parameter->name);
        self::assertSame(1, $parameter->position);
        self::assertTrue($parameter->hasDefault);
        self::assertSame(60, $parameter->default);
    }

    public function testAParameterWithoutADeclaredDefaultCarriesNone(): void
    {
        $parameter = new StrategyParameter('seconds', 0);

        self::assertFalse($parameter->hasDefault);
        self::assertNull($parameter->default);
    }
}
