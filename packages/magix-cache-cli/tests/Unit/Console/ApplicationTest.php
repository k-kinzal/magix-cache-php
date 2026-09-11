<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use Magix\Cache\Cli\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyArgumentBinder::class)]
#[UsesNamespace('Magix\Cache\Runtime\Parameter')]
#[Medium]
final class ApplicationTest extends TestCase
{
    public function testConsoleRegistersEveryMagixCommand(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();

        self::assertTrue($application->has('analyze'));
        self::assertFalse($application->has('boundaries'));
        self::assertFalse($application->has('ls'));
        self::assertFalse($application->has('lint'));
        self::assertTrue($application->has('list'));
        self::assertTrue($application->has('key'));
        self::assertSame('magix', $application->getName());
    }
}
