<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use JsonException;
use Magix\Cache\Cli\Console\AnalyzeCommand;
use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Console\CatalogLoader;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Package\Cli\Fixture\Display\InspectionQuery;
use Tests\Package\Cli\Fixture\Display\InventoryLookup;
use Tests\Package\Cli\Fixture\Project\ProductQuery;
use Tests\Package\Cli\Fixture\Project\ViewerQuery;

#[CoversClass(AnalyzeCommand::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyArgumentBinder::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesNamespace('Magix\Cache\Runtime\Parameter')]
#[UsesClass(\Magix\Cache\Strategy\Contract\ExpiresAt::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\ConstructorArg::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\Ttl::class)]
#[UsesClass(\Magix\Cache\Strategy\Contract\TtlRange::class)]
final class AnalyzeCommandTest extends TestCase
{
    public function testAnalyzeShowsBubbledMetadataWithoutAutomaticTtlDeclarations(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'BubblingPageQuery::explicit',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('ttl          120s (inherited from BubblingPageQuery::execute)', $output);
        self::assertStringContainsString('visibility   private', $output);
        self::assertStringContainsString('tags         page', $output);
        self::assertStringContainsString('storable     yes', $output);
        self::assertStringContainsString('policy       #[Cache]', $output);
        self::assertStringContainsString('BubblingPageQuery::explicit  ttl 120s  private', $output);
        self::assertStringContainsString('BubblingPageQuery::execute  ttl 120s  private', $output);
        self::assertStringContainsString('ProductPageQuery::execute  ttl 120s', $output);
        self::assertStringNotContainsString('Ttl::Auto', $output);
        self::assertStringNotContainsString('local restriction:', $output);
    }

    public function testAnalyzeHighlightsAParentOverrideWhenStrategyMetadataIsUnknown(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'TimedPage::fixed',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/TtlAlternatives'],
        ], ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('300s', $tester->getDisplay());
        self::assertStringContainsString("\033[33m300s\033[39m", $tester->getDisplay());
        self::assertStringNotContainsString('local restriction:', $tester->getDisplay());
        self::assertStringContainsString('TimedQuery::execute', $tester->getDisplay());
    }

    public function testAnalyzeHighlightsLocalOverridesAtTheRootAndInsideTheTree(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'RestrictedPageQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture/Project']];

        $tester->execute($arguments, ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("\033[33m10s\033[39m", $tester->getDisplay());
        self::assertStringContainsString("\033[33mprivate\033[39m", $tester->getDisplay());
        self::assertStringContainsString("\033[37m  ttl 60s  shared  tags inventory\033[39m", $tester->getDisplay());
        self::assertStringNotContainsString('local restriction:', $tester->getDisplay());

        $tester->execute([...$arguments, 'boundary' => 'RestrictedPageQuery::show'], ['decorated' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ttl          10s', $tester->getDisplay());
        self::assertStringContainsString('RestrictedPageQuery::execute  ttl 10s  private  tags inventory', $tester->getDisplay());
        self::assertStringNotContainsString('local restriction:', $tester->getDisplay());
        self::assertStringNotContainsString("\033[", $tester->getDisplay());
        self::assertStringNotContainsString('<fg=', $tester->getDisplay());
    }

    /**
     * @throws JsonException
     */
    public function testAnalyzeKeepsMetadataUnannotatedAndStylesMermaidNodesByBehavior(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'RestrictedPageQuery::show', '--path' => ['packages/magix-cache-cli/tests/Fixture/Project']];

        $tester->execute([...$arguments, '--format' => 'json']);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertIsArray($data['effective']);
        self::assertArrayNotHasKey('localOverrides', $data['effective']);
        self::assertIsArray($data['dependencies']);
        self::assertIsArray($data['dependencies'][0]);
        self::assertIsArray($data['dependencies'][0]['effective']);
        self::assertArrayNotHasKey('localOverrides', $data['dependencies'][0]['effective']);

        $tester->execute([...$arguments, '--format' => 'mermaid']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('n0_0["RestrictedPageQuery::execute<br/>10s - private"]', $tester->getDisplay());
        self::assertStringNotContainsString('local restriction:', $tester->getDisplay());
        self::assertStringContainsString('style n0_0 fill:#fff3cd', $tester->getDisplay());
        self::assertStringContainsString('style n0 fill:#e9ecef', $tester->getDisplay());
        self::assertStringContainsString('style n0_0_0 fill:#ffffff', $tester->getDisplay());
    }

    public function testAnalyzeShowsDetachedQueriesInjectedIntoActionParameters(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductController::injected',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ttl          unconstrained', $tester->getDisplay());
        self::assertStringContainsString('tags         -', $tester->getDisplay());
        self::assertStringContainsString('InventoryQuery::execute', $tester->getDisplay());
    }

    public function testAnalyzeKeepsAnUncachedControllerActionDetached(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductController::show',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('ProductController::show (uncached entry point)', $output);
        self::assertStringContainsString('ttl          unconstrained', $output);
        self::assertStringContainsString('visibility   shared', $output);
        self::assertStringContainsString('tags         -', $output);
        self::assertStringContainsString('key          none (uncached entry point)', $output);
        self::assertStringContainsString('policy       none (uncached entry point)', $output);
        self::assertStringContainsString('storable     no', $output);
        self::assertStringContainsString('ProductQuery::execute', $output);
        self::assertStringContainsString('InventoryQuery::execute', $output);
        self::assertStringContainsString('ViewerQuery::execute', $output);
        self::assertStringNotContainsString('LogicException', $output);
    }

    /**
     * @throws JsonException
     */
    public function testAnalyzeRendersAnUncachedRootInJsonAndMermaid(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();
        $json = new CommandTester($application->find('analyze'));
        $mermaid = new CommandTester($application->find('analyze'));
        $arguments = [
            'boundary' => 'ProductController::show',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ];

        $json->execute([...$arguments, '--format' => 'json']);
        $mermaid->execute([...$arguments, '--format' => 'mermaid']);

        $json->assertCommandIsSuccessful();
        $mermaid->assertCommandIsSuccessful();
        $data = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('entry-point', $data['kind']);
        self::assertNull($data['policy']);
        self::assertNull($data['key']);
        self::assertIsArray($data['effective']);
        self::assertSame('shared', $data['effective']['visibility']);
        self::assertFalse($data['effective']['storable']);
        self::assertSame([], $data['effective']['problems']);
        self::assertIsArray($data['dependencies']);
        self::assertCount(3, $data['dependencies']);
        self::assertStringContainsString('ProductController::show (uncached entry point)<br/>unconstrained - shared', $mermaid->getDisplay());
    }

    public function testAnalyzeFollowsAnUncachedMethodCallingAnotherUncachedMethod(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductController::index',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ttl          unconstrained', $tester->getDisplay());
        self::assertStringNotContainsString('ProductController::show (uncached)', $tester->getDisplay());
        self::assertStringContainsString('InventoryQuery::execute', $tester->getDisplay());
    }

    public function testAnalyzeRendersTheComposedTreeOfABoundary(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductPageQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ProductPageQuery::execute', $tester->getDisplay());
        self::assertStringContainsString('ttl          120s', $tester->getDisplay());
        self::assertStringContainsString('private (inherited from ViewerQuery::execute)', $tester->getDisplay());
        self::assertStringContainsString('$productId, $viewerId (ignored: $trace)', $tester->getDisplay());
        self::assertStringContainsString('InventoryQuery::execute', $tester->getDisplay());
    }

    public function testRenderSupportsJsonAndMermaidOutput(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();
        $json = new CommandTester($application->find('analyze'));
        $mermaid = new CommandTester($application->find('analyze'));

        $json->execute([
            'boundary' => 'HomeQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => 'json',
        ]);
        $mermaid->execute([
            'boundary' => 'HomeQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => 'mermaid',
        ]);

        self::assertJson($json->getDisplay());
        self::assertStringContainsString('flowchart TD', $mermaid->getDisplay());
    }

    public function testAnalyzeFailsWithSuggestionsForAnUnknownBoundary(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $status = $tester->execute([
            'boundary' => 'MissingQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Known boundaries:', $tester->getDisplay());
    }

    public function testSuggestionsExplainAnEmptyCatalog(): void
    {
        $command = new AnalyzeCommand(new CatalogLoader(dirname(__DIR__, 5)));
        $many = array_map(
            static fn (int $index): BoundaryDeclaration => new BoundaryDeclaration('App\Query'.$index, 'execute', 'a.php', 1),
            range(1, 12),
        );

        self::assertStringContainsString('No cache boundaries were found', $command->suggestions([]));
        self::assertStringContainsString('and 2 more', $command->suggestions($many));
    }

    /**
     * @throws JsonException
     */
    public function testShowsOrdinaryCallsAndLeavesWithoutComposingDetachedMetadata(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'InspectionQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture'], '--format' => 'json'];
        $tester->execute($arguments);
        $baseline = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        self::assertIsArray($baseline['dependencies']);
        self::assertSame([ViewerQuery::class.'::execute', InventoryLookup::class.'::get'], array_column($baseline['dependencies'], 'boundary'));
        self::assertIsArray($baseline['effective']);
        self::assertIsArray($baseline['effective']['ttl']);
        self::assertSame('known', $baseline['effective']['ttl']['state']);
        self::assertSame(120, $baseline['effective']['ttl']['seconds']);
        self::assertTrue($baseline['effective']['storable']);
        self::assertFalse($baseline['effective']['visibilityUnknown']);
        self::assertFalse($baseline['effective']['tagsUnknown']);
        self::assertSame([], $baseline['effective']['problems']);
        self::assertSame([], $baseline['analysisGaps']);

        $tester->execute([...$arguments, '--uncached' => 'all']);
        $tester->assertCommandIsSuccessful();
        $expanded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($expanded);
        self::assertSame($baseline['effective'], $expanded['effective']);
        self::assertIsArray($expanded['dependencies']);
        self::assertSame([
            ViewerQuery::class.'::execute', InventoryLookup::class.'::get', InspectionQuery::class.'::offset',
        ], array_column($expanded['dependencies'], 'boundary'));
        self::assertIsArray($expanded['dependencies'][1]);
        self::assertSame('uncached', $expanded['dependencies'][1]['kind']);
        self::assertNull($expanded['dependencies'][1]['policy']);
        self::assertNull($expanded['dependencies'][1]['key']);
        self::assertIsArray($expanded['dependencies'][1]['dependencies']);
        self::assertSame([ProductQuery::class.'::execute'], array_column($expanded['dependencies'][1]['dependencies'], 'boundary'));

        $tester->execute([...$arguments, '--uncached' => 'all', '--ignore' => ['*Lookup', 'ViewerQuery']]);
        $filtered = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($filtered);
        self::assertSame($expanded['effective'], $filtered['effective']);
        self::assertSame($expanded['analysisGaps'], $filtered['analysisGaps']);
        self::assertIsArray($filtered['dependencies']);
        self::assertSame([InspectionQuery::class.'::offset'], array_column($filtered['dependencies'], 'boundary'));
    }

    #[DataProvider('providerFormatsAndUncachedModes')]
    public function testAnalyzedExtractionsStayFreeOfGapsInEveryUncachedMode(string $format, string $uncached): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'InspectionQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
            '--format' => $format,
            '--uncached' => $uncached,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('cache propagation unanalyzed:', $tester->getDisplay());
        self::assertStringContainsString('ProductQuery::execute', $tester->getDisplay());
    }

    #[DataProvider('providerFormatsAndUncachedModes')]
    public function testIgnorePrunesCachedSubtreesIndependentlyOfUncachedMode(string $format, string $uncached): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'BubblingPageQuery::explicit',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => $format,
            '--uncached' => $uncached,
            '--ignore' => ['BubblingPageQuery::execute'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('BubblingPageQuery::explicit', $tester->getDisplay());
        self::assertStringNotContainsString('ProductPageQuery::execute', $tester->getDisplay());
        self::assertStringNotContainsString('InventoryQuery::execute', $tester->getDisplay());
        self::assertStringContainsString('private', $tester->getDisplay());
    }

    /**
     * @throws JsonException
     */
    public function testIgnoredCachedDependenciesStillConstrainTheCompleteEffectiveResult(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'ProductPageQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'], '--format' => 'json'];
        $tester->execute($arguments);
        $baseline = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);

        $tester->execute([...$arguments, '--ignore' => ['ProductQuery', 'ViewerQuery']]);
        $filtered = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($filtered);
        self::assertSame($baseline['effective'], $filtered['effective']);
        self::assertIsArray($filtered['dependencies']);
        self::assertCount(1, $filtered['dependencies']);
    }

    #[DataProvider('providerTextFormats')]
    public function testTextFormatsIdentifyOrdinaryCallsAndApplyTheSameSubtreeFilter(string $format): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $arguments = ['boundary' => 'InspectionQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture'], '--format' => $format, '--uncached' => 'all'];
        $tester->execute($arguments);
        self::assertStringContainsString('InventoryLookup::get (uncached)', $tester->getDisplay());
        self::assertStringContainsString('InspectionQuery::offset (uncached)', $tester->getDisplay());
        self::assertStringContainsString('ProductQuery::execute', $tester->getDisplay());

        $tester->execute([...$arguments, '--ignore' => ['*Lookup']]);
        self::assertStringNotContainsString('InventoryLookup::get (uncached)', $tester->getDisplay());
        self::assertStringNotContainsString('ProductQuery::execute  ttl', $tester->getDisplay());
        self::assertStringNotContainsString('ProductQuery::execute<br/>', $tester->getDisplay());
        self::assertStringNotContainsString('cache propagation unanalyzed:', $tester->getDisplay());
        self::assertStringContainsString('InspectionQuery::offset (uncached)', $tester->getDisplay());
    }

    #[DataProvider('providerFormatsAndUncachedModes')]
    public function testIgnoreAlsoAppliesToTheSelectedRoot(string $format, string $uncached): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'ProductController::show',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => $format,
            '--uncached' => $uncached,
            '--ignore' => ['*Controller'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame($format === 'json' ? '[]' : 'All matching roots were excluded by --ignore.', trim($tester->getDisplay()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerFormatsAndUncachedModes(): iterable
    {
        foreach (['tree', 'json', 'mermaid'] as $format) {
            foreach (['between', 'all', 'none'] as $mode) {
                yield $format.' '.$mode => [$format, $mode];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providerTextFormats(): iterable
    {
        yield 'tree' => ['tree'];
        yield 'mermaid' => ['mermaid'];
    }

    #[DataProvider('providerExpirationBoundaries')]
    public function testAnalyzeRendersDailyTimesSeparatelyFromTtl(string $boundary, string $time): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => $boundary, '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration']];
        $tester->execute($arguments);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('strategy at  '.$time, $tester->getDisplay());
        self::assertStringContainsString('expires by '.$time, $tester->getDisplay());
        self::assertStringContainsString('ttl          unknown', $tester->getDisplay());
        self::assertStringNotContainsString('86400s', $tester->getDisplay());
        self::assertStringNotContainsString('requires a finite upstream expiration', $tester->getDisplay());

        $tester->execute([...$arguments, '--format' => 'mermaid']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('expires by '.$time, $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerExpirationBoundaries(): iterable
    {
        yield 'point' => ['NoonQuery::single', 'daily 12:00 UTC'];
        yield 'window' => ['NoonQuery::window', 'daily 12:00-12:15 Asia/Tokyo'];
        yield 'overnight' => ['NoonQuery::overnight', 'daily 23:55:30-00:10:15 (+1 day) UTC'];
        yield 'runtime binding' => ['NoonQuery::dynamic', 'daily ?-12:15 Asia/Tokyo'];
        yield 'multiple' => ['NoonQuery::multiple', 'earliest of (daily 09:00 Asia/Tokyo; daily 23:55:30-00:10:15 (+1 day) America/New_York; daily 18:00-18:15 Asia/Tokyo)'];
        yield 'multiple runtime binding' => ['NoonQuery::multipleDynamic', 'earliest of (daily 09:00 Asia/Tokyo; daily 23:55:30-00:10:15 (+1 day) America/New_York; daily ?-18:15 Asia/Tokyo)'];
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('providerMultipleExpirationBoundaries')]
    public function testAnalyzePreservesEveryRepeatedExpirationThroughCompositionAndPolicies(string $boundary, ?int $upperBound, ?string $at): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute(['boundary' => $boundary, '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration'], '--format' => 'json']);
        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['effective']);
        self::assertIsArray($data['effective']['ttl']);
        self::assertSame('unknown', $data['effective']['ttl']['state']);
        self::assertNull($data['effective']['ttl']['seconds']);
        self::assertSame($upperBound, $data['effective']['ttl']['upperBound']);
        self::assertTrue($data['effective']['ttl']['finite']);
        self::assertSame([], $data['effective']['problems']);
        $expected = [
            ['at' => '09:00', 'until' => null, 'timezone' => 'Asia/Tokyo', 'window' => false, 'crossesMidnight' => false, 'recurrence' => 'daily'],
            ['at' => '23:55:30', 'until' => '00:10:15', 'timezone' => 'America/New_York', 'window' => true, 'crossesMidnight' => true, 'recurrence' => 'daily'],
            ['at' => $at, 'until' => '18:15', 'timezone' => 'Asia/Tokyo', 'window' => true, 'crossesMidnight' => $at === null ? null : false, 'recurrence' => 'daily'],
        ];
        self::assertSame($expected, $data['effective']['expirationConstraints']);

    }

    /**
     * @throws JsonException
     */
    public function testAnalyzeKeepsRepeatedCandidateClocksInNestedStrategySteps(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute(['boundary' => 'NoonQuery::multipleComposed', '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration'], '--format' => 'json']);
        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['effective']);
        self::assertIsArray($data['effective']['expirationConstraints']);
        self::assertCount(3, $data['effective']['expirationConstraints']);
        self::assertIsArray($data['strategy']);
        self::assertSame($data['effective']['expirationConstraints'], $data['strategy']['expirations']);
        self::assertIsArray($data['strategy']['steps']);
        self::assertIsArray($data['strategy']['steps'][0]);
        self::assertSame($data['strategy']['expirations'], $data['strategy']['steps'][0]['expirations']);
    }

    /**
     * @return iterable<string, array{string, int|null, string|null}>
     */
    public static function providerMultipleExpirationBoundaries(): iterable
    {
        yield 'direct' => ['NoonQuery::multiple', null, '18:00'];
        yield 'invocation' => ['NoonQuery::multipleDynamic', null, null];
        yield 'nested composition' => ['NoonQuery::multipleComposed', null, '18:00'];
        yield 'automatic parent' => ['NoonPage::multipleAutomatic', null, '18:00'];
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('providerExpirationPolicies')]
    public function testAnalyzePropagatesDailyWindowsThroughPoliciesAndUncachedEntryPoints(string $method, ?int $upperBound): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $arguments = ['boundary' => 'NoonPage::'.$method, '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration']];
        $tester->execute([...$arguments, '--format' => 'json']);
        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['effective']);
        self::assertIsArray($data['effective']['ttl']);
        self::assertSame('unknown', $data['effective']['ttl']['state']);
        self::assertNull($data['effective']['ttl']['seconds']);
        self::assertSame($upperBound, $data['effective']['ttl']['upperBound']);
        self::assertTrue($data['effective']['ttl']['finite']);
        self::assertSame([], $data['effective']['problems']);
        self::assertSame([[
            'at' => '12:00', 'until' => '12:15', 'timezone' => 'Asia/Tokyo',
            'window' => true, 'crossesMidnight' => false, 'recurrence' => 'daily',
        ]], $data['effective']['expirationConstraints']);

        $tester->execute($arguments);
        self::assertStringContainsString('expires by   daily 12:00-12:15 Asia/Tokyo', $tester->getDisplay());
    }

    /**
     * @throws JsonException
     */
    public function testAnalyzeOutermostClockOverridesInnerClocksAndDurations(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'NoonQuery::composed', '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration']];
        $tester->execute([...$arguments, '--format' => 'json']);
        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['strategy']);
        self::assertIsArray($data['strategy']['expirations']);
        self::assertCount(1, $data['strategy']['expirations']);
        self::assertIsArray($data['strategy']['steps']);
        self::assertIsArray($data['strategy']['steps'][0]);
        self::assertArrayHasKey('expirations', $data['strategy']['steps'][0]);
        self::assertIsArray($data['effective']);
        self::assertIsArray($data['effective']['ttl']);
        self::assertNull($data['effective']['ttl']['upperBound']);
        self::assertNull($data['effective']['ttl']['lowerBound']);
        self::assertTrue($data['effective']['ttl']['finite']);
        self::assertSame([], $data['effective']['problems']);

        $tester->execute([...$arguments, '--format' => 'mermaid']);
        self::assertStringContainsString('expires by daily 12:00-12:15 Asia/Tokyo', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function providerExpirationPolicies(): iterable
    {
        yield 'auto' => ['automatic', null];
        yield 'bounded' => ['bounded', 30];
        yield 'uncached entry' => ['show', 30];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $expectedIgnored
     * @throws JsonException
     */
    #[DataProvider('providerUncachedJsonModes')]
    public function testJsonModesPreserveAnalysisAndPromoteCachedChildren(string $mode, array $expected, array $expectedIgnored): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'InspectionQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture'], '--format' => 'json'];
        $tester->execute([...$arguments, '--uncached' => 'all']);
        $complete = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($complete);
        $tester->execute([...$arguments, '--uncached' => $mode]);
        $tester->assertCommandIsSuccessful();
        $visible = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($visible);
        self::assertSame($complete['effective'], $visible['effective']);
        self::assertSame($complete['analysisGaps'], $visible['analysisGaps']);
        self::assertIsArray($visible['dependencies']);
        self::assertSame($expected, array_column($visible['dependencies'], 'boundary'));

        $tester->execute([...$arguments, '--uncached' => $mode, '--ignore' => ['*Lookup']]);
        $ignored = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($ignored);
        self::assertSame($complete['effective'], $ignored['effective']);
        self::assertSame($complete['analysisGaps'], $ignored['analysisGaps']);
        self::assertIsArray($ignored['dependencies']);
        self::assertSame($expectedIgnored, array_column($ignored['dependencies'], 'boundary'));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('providerUncachedTextFormatsAndModes')]
    public function testTextFormatsShowOnlyTheSelectedOrdinaryRows(string $format, string $mode, array $expected): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute(['boundary' => 'InspectionQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture'], '--format' => $format, '--uncached' => $mode]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringNotContainsString('cache propagation unanalyzed:', $output);
        self::assertStringContainsString($format === 'tree' ? 'ProductQuery::execute  ttl 20s' : 'ProductQuery::execute<br/>20s', $output);

        preg_match_all('/([A-Za-z]+::[A-Za-z]+) \(uncached\)/', $output, $matches);
        self::assertSame($expected, $matches[1]);
    }

    #[DataProvider('providerUncachedFormats')]
    public function testDefaultIsBetween(string $format): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'InspectionQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture/Display', 'packages/magix-cache-cli/tests/Fixture/Project'], '--format' => $format];
        $tester->execute($arguments);
        $default = $tester->getDisplay();
        $tester->execute([...$arguments, '--uncached' => 'between']);
        self::assertSame($default, $tester->getDisplay());
    }

    #[DataProvider('providerUncachedFormats')]
    public function testNoneRetainsAnExplicitUncachedRootAndHonorsTheOriginalDepth(string $format): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'ProductController::index', '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'], '--format' => $format, '--uncached' => 'none'];
        $tester->execute($arguments);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ProductController::index', $tester->getDisplay());
        self::assertStringNotContainsString($format === 'json' ? '"kind": "uncached"' : '(uncached)', $tester->getDisplay());
        self::assertStringContainsString('ProductQuery::execute', $tester->getDisplay());

        $tester->execute([...$arguments, '--depth' => 1]);
        self::assertStringNotContainsString('ProductQuery::execute', $tester->getDisplay());
        $tester->execute([...$arguments, '--ignore' => ['ProductController::show']]);
        self::assertStringNotContainsString('ProductQuery::execute', $tester->getDisplay());
        $tester->execute([...$arguments, '--ignore' => ['ProductController::index']]);
        self::assertSame($format === 'json' ? '[]' : 'All matching roots were excluded by --ignore.', trim($tester->getDisplay()));
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function providerUncachedJsonModes(): iterable
    {
        yield 'between' => ['between', [ViewerQuery::class.'::execute', InventoryLookup::class.'::get'], [ViewerQuery::class.'::execute']];
        yield 'all' => ['all', [ViewerQuery::class.'::execute', InventoryLookup::class.'::get', InspectionQuery::class.'::offset'], [ViewerQuery::class.'::execute', InspectionQuery::class.'::offset']];
        yield 'none' => ['none', [ViewerQuery::class.'::execute', ProductQuery::class.'::execute'], [ViewerQuery::class.'::execute']];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providerUncachedFormats(): iterable
    {
        foreach (['tree', 'json', 'mermaid'] as $format) {
            yield $format => [$format];
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function providerUncachedTextFormatsAndModes(): iterable
    {
        foreach (['tree', 'mermaid'] as $format) {
            yield $format.' between' => [$format, 'between', ['InventoryLookup::get']];
            yield $format.' all' => [$format, 'all', ['InventoryLookup::get', 'InspectionQuery::offset']];
            yield $format.' none' => [$format, 'none', []];
        }
    }
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function providerClockOverrides(): iterable
    {
        yield 'single clock' => ['fixed', 60];
        yield 'multiple clocks' => ['multipleComposed', 30];
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('providerClockOverrides')]
    public function testAnalyzeFixedPolicyReplacesDailyExpirationConstraints(string $method, int $ttl): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'NoonPage::'.$method, '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration']];
        $tester->execute([...$arguments, '--format' => 'json']);
        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['effective']);
        self::assertIsArray($data['effective']['ttl']);
        self::assertSame('known', $data['effective']['ttl']['state']);
        self::assertSame($ttl, $data['effective']['ttl']['seconds']);
        self::assertArrayNotHasKey('expirationConstraints', $data['effective']);
        self::assertIsArray($data['dependencies']);
        self::assertIsArray($data['dependencies'][0]);
        self::assertIsArray($data['dependencies'][0]['effective']);
        self::assertArrayHasKey('expirationConstraints', $data['dependencies'][0]['effective']);
    }
}
