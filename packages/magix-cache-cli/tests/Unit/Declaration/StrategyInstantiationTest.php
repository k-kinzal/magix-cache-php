<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Declaration;

use Magix\Cache\Cli\Declaration\StrategyArgument;
use Magix\Cache\Cli\Declaration\StrategyInstantiation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrategyInstantiation::class)]
#[UsesClass(StrategyArgument::class)]
final class StrategyInstantiationTest extends TestCase
{
    public function testAnInstantiationKeepsTheWrittenConstruction(): void
    {
        $argument = new StrategyArgument('seconds', 60);
        $instantiation = new StrategyInstantiation('App\Cache\RedisStrategy', [$argument], true, 18);

        self::assertSame('App\Cache\RedisStrategy', $instantiation->class);
        self::assertSame([$argument], $instantiation->arguments);
        self::assertTrue($instantiation->viaCreate);
        self::assertSame(18, $instantiation->line);
    }

    public function testAnInstantiationDefaultsToABareNewWithoutArguments(): void
    {
        $instantiation = new StrategyInstantiation('App\Cache\RedisStrategy');

        self::assertSame([], $instantiation->arguments);
        self::assertFalse($instantiation->viaCreate);
        self::assertSame(0, $instantiation->line);
    }
}
