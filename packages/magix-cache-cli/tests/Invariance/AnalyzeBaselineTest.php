<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Invariance;

use Magix\Cache\Cli\Console\AnalyzeCommand;
use Magix\Cache\Cli\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Freezes complete reports before replacing the analyzer's value resolver.
 *
 * Expectations are captured with the original implementation and reviewed
 * separately from the refactor. Tests never regenerate their expectations.
 */
#[CoversClass(AnalyzeCommand::class)]
#[UsesNamespace('Magix\Cache')]
#[Medium]
final class AnalyzeBaselineTest extends TestCase
{
    /**
     * Every field, diagnostic, source position and rendered row must survive.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('reports')]
    public function testAnalyzePreservesTheCompleteReport(string $snapshot, array $arguments, bool $decorated): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 4)))->console()->find('analyze'));
        $tester->execute($arguments, ['decorated' => $decorated]);

        $tester->assertCommandIsSuccessful();
        self::assertStringEqualsFile(__DIR__.'/Baseline/'.$snapshot, $tester->getDisplay());
    }

    /**
     * Covers declarations, composition, unknowns and independent display filters.
     *
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function reports(): iterable
    {
        $fixtures = 'packages/magix-cache-cli/tests/Fixture/';
        $cases = [
            'controller' => ['Project', 'ProductController'],
            'policy' => ['Project', 'BubblingPageQuery'],
            'strategy' => ['Project', 'ProductQuery'],
            'unknown' => ['Project', 'BrokenStrategyQuery'],
            'partial' => ['Display', 'UnverifiedPageQuery'],
            'expiration' => ['Expiration', 'NoonPage'],
            'alternatives' => ['TtlAlternatives', 'TimedPage'],
            'composition' => ['FunctionalComposition', 'Page'],
            'async' => ['AsyncComposition', 'AsyncQueries'],
            'parameters' => ['', 'ParameterQuery'],
        ];

        foreach ($cases as $name => [$path, $boundary]) {
            foreach (['json', 'tree', 'mermaid'] as $format) {
                $snapshot = $name.'.'.$format;
                yield $snapshot => [$snapshot, [
                    'boundary' => $boundary,
                    '--path' => [$fixtures.$path],
                    '--format' => $format,
                    '--uncached' => $format === 'json' ? 'all' : 'between',
                ], $format === 'tree'];
            }
        }

        foreach (['between', 'all', 'none'] as $mode) {
            $snapshot = 'filtered-'.$mode.'.json';
            yield $snapshot => [$snapshot, [
                'boundary' => 'ProductController::show',
                '--path' => [$fixtures.'Project'],
                '--format' => 'json',
                '--uncached' => $mode,
                '--depth' => 2,
                '--ignore' => ['ViewerQuery'],
            ], false];
        }
    }
}
