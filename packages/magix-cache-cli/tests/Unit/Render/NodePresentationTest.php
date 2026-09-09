<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\MermaidRenderer;
use Magix\Cache\Cli\Render\NodePresentation;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(NodePresentation::class)]
#[Medium]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesNamespace('Magix\Cache\Runtime\Parameter')]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyArgumentBinder::class)]
#[UsesClass(Visibility::class)]
final class NodePresentationTest extends TestCase
{
    #[DataProvider('providerStorageStates')]
    public function testColorDoesNotConfuseRuntimeStorageWithErrors(CacheNode $node, string $color, string $storage): void
    {
        $presentation = new NodePresentation();

        self::assertSame($color, $presentation->color($node));
        self::assertSame($storage, $presentation->storage($node));
        $fill = ['white' => '#ffffff', 'gray' => '#e9ecef', 'yellow' => '#fff3cd', 'red' => '#f8d7da'][$color];
        self::assertStringContainsString('style n0 fill:'.$fill, (new MermaidRenderer())->render($node));
    }

    /**
     * @return iterable<string, array{CacheNode, string, string}>
     */
    public static function providerStorageStates(): iterable
    {
        $boundary = new BoundaryDeclaration('Query', 'get', 'query.php', 1);
        $dynamic = new CacheEffect(TtlEstimate::unknown(lowerBound: 0, finite: true), visibilityUnknown: true);
        $warnings = ['Lookup::get: depth limit reached; increase --depth'];

        yield 'runtime decision' => [new CacheNode($boundary, $dynamic), 'white', 'runtime-dependent'];
        yield 'proven storage' => [new CacheNode($boundary, new CacheEffect(TtlEstimate::known(30), storable: true)), 'white', 'yes'];
        yield 'incomplete analysis' => [new CacheNode($boundary, $dynamic, analysisWarnings: $warnings), 'yellow', 'unknown (analysis incomplete)'];
        yield 'NoStore with incomplete analysis' => [new CacheNode($boundary, new CacheEffect(TtlEstimate::unknown(), Visibility::NoStore), analysisWarnings: $warnings), 'gray', 'no'];
        yield 'zero TTL' => [new CacheNode($boundary, new CacheEffect(TtlEstimate::known(0))), 'gray', 'no'];
        yield 'error before NoStore and warnings' => [new CacheNode($boundary, new CacheEffect(TtlEstimate::invalid('invalid declaration'), Visibility::NoStore), analysisWarnings: $warnings), 'red', 'no'];
        yield 'declaration problem' => [new CacheNode($boundary, new CacheEffect(TtlEstimate::known(30), problems: ['missing reference'])), 'red', 'no'];
        yield 'ordinary entry point' => [new CacheNode(new BoundaryDeclaration('Controller', 'show', 'controller.php', 1, isCacheBoundary: false), $dynamic), 'gray', 'no'];
    }

    #[DataProvider('providerFilters')]
    public function testWarningsSurviveHiddenAndIgnoredOrdinaryMethods(string $mode, bool $ignore): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = [
            'boundary' => 'InspectionQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
            '--depth' => 1,
            '--uncached' => $mode,
            '--ignore' => $ignore ? ['*Lookup'] : [],
        ];
        $tester->execute($arguments, ['decorated' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("\033[33;1mInspectionQuery::execute\033[39;22m", $tester->getDisplay());
        self::assertStringContainsString('InventoryLookup::get: depth limit reached, dependencies not expanded; increase --depth', $tester->getDisplay());
        self::assertSame(1, substr_count($tester->getDisplay(), 'InventoryLookup::get: depth limit reached'));
        self::assertStringNotContainsString("\033[31m", $tester->getDisplay());

        $tester->execute([...$arguments, '--format' => 'mermaid']);
        self::assertStringContainsString('style n0 fill:#fff3cd', $tester->getDisplay());
        self::assertStringContainsString('increase --depth', $tester->getDisplay());
        $tester->execute([...$arguments, '--format' => 'json']);
        self::assertStringContainsString('"analysisWarnings": [', $tester->getDisplay());
        self::assertStringContainsString('increase --depth', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providerFilters(): iterable
    {
        foreach (['none', 'between', 'all'] as $mode) {
            yield $mode => [$mode, false];
            yield $mode.' ignored' => [$mode, true];
        }
    }

    public function testCompletingDepthAnalysisRestoresWhiteRows(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'BubblingPageQuery::explicit', '--path' => ['packages/magix-cache-cli/tests/Fixture']];
        $tester->execute([...$arguments, '--depth' => 1], ['decorated' => true]);
        self::assertStringContainsString("\033[33;1mBubblingPageQuery::explicit", $tester->getDisplay());
        $tester->execute([...$arguments, '--depth' => 8], ['decorated' => true]);
        self::assertStringContainsString("\033[37;1mBubblingPageQuery::explicit", $tester->getDisplay());
        self::assertStringNotContainsString('increase --depth', $tester->getDisplay());
    }

    public function testStorageShowsRuntimeDecisionsWithoutClaimingNonStorage(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'ExchangeRateQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture']];
        $tester->execute($arguments);
        self::assertStringContainsString('storable     runtime-dependent', $tester->getDisplay());
        $tester->execute([...$arguments, '--format' => 'json']);
        self::assertStringContainsString('"storable": false', $tester->getDisplay());
    }

    public function testInvalidDistinguishesValidNonStorageFromDefinitionErrors(): void
    {
        $presentation = new NodePresentation();
        self::assertFalse($presentation->invalid(new CacheEffect(TtlEstimate::known(0), Visibility::NoStore)));
        self::assertFalse($presentation->invalid(new CacheEffect(TtlEstimate::unknown())));
        self::assertTrue($presentation->invalid(new CacheEffect(TtlEstimate::invalid('missing expiration'))));
    }

    public function testDisabledDoesNotDisableALifetimeThatCanBePositiveAtRuntime(): void
    {
        $presentation = new NodePresentation();
        self::assertFalse($presentation->disabled(new CacheEffect(TtlEstimate::unknown(lowerBound: 0, finite: true))));
        self::assertTrue($presentation->disabled(new CacheEffect(TtlEstimate::known(0))));
        self::assertTrue($presentation->disabled(new CacheEffect(TtlEstimate::known(30), Visibility::NoStore)));
    }

    public function testMermaidHighlightsOverridesEvenWhenStorageDependsOnRuntime(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('Query', 'get', 'query.php', 1),
            new CacheEffect(TtlEstimate::unknown(lowerBound: 0, finite: true), localOverrides: ['ttl' => 'dynamic override']),
        );
        $presentation = new NodePresentation();
        self::assertSame('white', $presentation->color($node));
        self::assertSame('fill:#fff3cd,stroke:#b58100,color:#664d03', $presentation->mermaid($node));
    }
}
