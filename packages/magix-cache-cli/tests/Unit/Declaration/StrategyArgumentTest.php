<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\StrategyArgument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyArgument::class)]
final class StrategyArgumentTest extends TestCase
{
    public function testAnArgumentKeepsWhatWasWrittenAtTheCallSite(): void
    {
        $argument = new StrategyArgument('minimum', 60, 'ttl');

        self::assertSame('minimum', $argument->name);
        self::assertSame(60, $argument->value);
        self::assertSame('ttl', $argument->variable);
    }

    public function testALiteralArgumentCarriesNoVariableByDefault(): void
    {
        $argument = new StrategyArgument(null, 'products');

        self::assertNull($argument->name);
        self::assertSame('products', $argument->value);
        self::assertNull($argument->variable);
    }
}
