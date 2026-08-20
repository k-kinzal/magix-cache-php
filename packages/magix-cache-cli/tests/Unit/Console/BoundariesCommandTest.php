<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Console\BoundariesCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BoundariesCommand::class)]
#[UsesNamespace('Magix\Cache\Cli')]
final class BoundariesCommandTest extends TestCase
{
    public function testBoundariesListEveryDeclaredCache(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('boundaries'));

        $tester->execute(['--path' => ['packages/magix-cache-cli/tests/Fixture/Project']]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('BOUNDARY', $tester->getDisplay());
        self::assertStringContainsString('ProductQuery::execute', $tester->getDisplay());
        self::assertStringContainsString('boundaries', $tester->getDisplay());
    }

    public function testBoundariesCanBeFilteredAndEncodedAsJson(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();
        $tester = new CommandTester($application->find('boundaries'));
        $empty = new CommandTester($application->find('boundaries'));

        $tester->execute([
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--filter' => 'ViewerQuery',
            '--format' => 'json',
        ]);
        $empty->execute([
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--filter' => 'NothingMatchesThis',
        ]);

        self::assertJson($tester->getDisplay());
        self::assertStringContainsString('ViewerQuery', $tester->getDisplay());
        self::assertStringContainsString('No cache boundaries were found', $empty->getDisplay());
    }
}
