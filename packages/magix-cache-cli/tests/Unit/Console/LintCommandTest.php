<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Console\CatalogLoader;
use Magix\Cache\Cli\Console\LintCommand;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(LintCommand::class)]
#[UsesNamespace('Magix\Cache\Cli')]
final class LintCommandTest extends TestCase
{
    public function testLintFailsWhenABoundaryCannotWork(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('lint'));

        $status = $tester->execute(['--path' => ['packages/magix-cache-cli/tests/Fixture/Project']]);

        self::assertSame(1, $status);
        self::assertStringContainsString('missing-policy', $tester->getDisplay());
        self::assertStringContainsString('unscoped-private-key', $tester->getDisplay());
        self::assertStringContainsString('findings', $tester->getDisplay());
    }

    public function testLintReportsFindingsAsJson(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('lint'));

        $tester->execute([
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => 'json',
        ]);

        self::assertJson($tester->getDisplay());
    }

    public function testDescribeRendersOneFindingWithItsHint(): void
    {
        $command = new LintCommand(new CatalogLoader(dirname(__DIR__, 5)));
        $diagnostic = new Diagnostic(
            rule: 'missing-policy',
            severity: Severity::Error,
            boundary: 'App\PageQuery::execute',
            file: 'src/PageQuery.php',
            line: 31,
            message: 'cached() is called without a policy.',
            hint: 'Add #[Cache].',
        );

        $described = $command->describe($diagnostic);

        self::assertStringContainsString('src/PageQuery.php:31', $described);
        self::assertStringContainsString('missing-policy', $described);
        self::assertStringContainsString('hint: Add #[Cache].', $described);
    }

    public function testEncodeReturnsOneEntryPerFinding(): void
    {
        $command = new LintCommand(new CatalogLoader(dirname(__DIR__, 5)));
        $diagnostic = new Diagnostic(
            rule: 'clamped-ttl',
            severity: Severity::Notice,
            boundary: 'App\PageQuery::execute',
            file: 'src/PageQuery.php',
            line: 31,
            message: 'The declared ttl is always clamped.',
        );

        $encoded = $command->encode([$diagnostic]);

        self::assertJson($encoded);
        self::assertStringContainsString('"rule": "clamped-ttl"', $encoded);
        self::assertStringContainsString('"severity": "notice"', $encoded);
    }
}
