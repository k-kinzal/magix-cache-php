<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use Magix\Cache\Cli\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[UsesNamespace('Magix\Cache\Cli')]
final class ApplicationTest extends TestCase
{
    public function testConsoleRegistersEveryMagixCommand(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();

        self::assertTrue($application->has('analyze'));
        self::assertTrue($application->has('boundaries'));
        self::assertTrue($application->has('ls'));
        self::assertTrue($application->has('lint'));
        self::assertTrue($application->has('key'));
        self::assertSame('magix', $application->getName());
    }
}
