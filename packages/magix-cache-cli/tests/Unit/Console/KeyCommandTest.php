<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Console\CatalogLoader;
use Magix\Cache\Cli\Console\KeyCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(KeyCommand::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesNamespace('Magix\Cache\Runtime')]
final class KeyCommandTest extends TestCase
{
    public function testKeyPrintsTheHashOfOneCall(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('key'));

        $tester->execute([
            'boundary' => 'ProductQuery::execute',
            'arguments' => ['42'],
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('productId=42', $tester->getDisplay());
        self::assertStringContainsString('bfc136b0201bb228f9340e6eb474254677bb24f1037a4912ef0f74463ef8173a', $tester->getDisplay());
    }

    public function testKeyFailsForAnUnknownBoundary(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('key'));

        $status = $tester->execute([
            'boundary' => 'MissingQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('No cache boundary matches', $tester->getDisplay());
    }

    public function testDecodeReadsJsonValuesAndPlainStrings(): void
    {
        $command = new KeyCommand(new CatalogLoader(dirname(__DIR__, 5)));

        self::assertSame([42, 'en', ['a' => 1], true], $command->decode(['42', 'en', '{"a":1}', 'true']));
    }
}
