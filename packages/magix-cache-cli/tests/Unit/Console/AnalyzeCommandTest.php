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
        self::assertStringContainsString('ttl          20s (inherited from BubblingPageQuery::execute)', $output);
        self::assertStringContainsString('visibility   private', $output);
        self::assertStringContainsString('tags         inventory, page, product, viewer', $output);
        self::assertStringContainsString('storable     yes', $output);
        self::assertStringContainsString('policy       #[Cache]', $output);
        self::assertStringContainsString('BubblingPageQuery::explicit  ttl 20s  private', $output);
        self::assertStringContainsString('BubblingPageQuery::execute  ttl 20s  private', $output);
        self::assertStringContainsString('ProductPageQuery::execute  ttl 20s (declared 120s)', $output);
        self::assertStringNotContainsString('Ttl::Auto', $output);
        self::assertStringNotContainsString('local restriction:', $output);
    }

    public function testAnalyzeHighlightsPartialCapsWithoutCollapsingTtlAlternatives(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'TimedPage::fixed',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/TtlAlternatives'],
        ], ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("\033[33m30/300s\033[39m", $tester->getDisplay());
        self::assertStringNotContainsString('local restriction:', $tester->getDisplay());
        self::assertStringContainsString('TimedQuery::execute', $tester->getDisplay());
    }

    public function testAnalyzeHighlightsLocalRestrictionsAtTheRootAndInsideTheTree(): void
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
    public function testAnalyzeKeepsJsonUnannotatedAndStylesOnlyTheAffectedMermaidNode(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'RestrictedPageQuery::show', '--path' => ['packages/magix-cache-cli/tests/Fixture/Project']];

        $tester->execute([...$arguments, '--format' => 'json']);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertIsArray($data['effective']);
        self::assertArrayNotHasKey('localRestrictions', $data['effective']);
        self::assertIsArray($data['dependencies']);
        self::assertIsArray($data['dependencies'][0]);
        self::assertIsArray($data['dependencies'][0]['effective']);
        self::assertArrayNotHasKey('localRestrictions', $data['dependencies'][0]['effective']);

        $tester->execute([...$arguments, '--format' => 'mermaid']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('n0_0["RestrictedPageQuery::execute<br/>10s - private"]', $tester->getDisplay());
        self::assertStringNotContainsString('local restriction:', $tester->getDisplay());
        self::assertStringContainsString('style n0_0 fill:#fff3cd', $tester->getDisplay());
        self::assertStringNotContainsString('style n0 ', $tester->getDisplay());
        self::assertStringNotContainsString('style n0_0_0 ', $tester->getDisplay());
    }

    public function testAnalyzeComposesQueriesInjectedIntoActionParameters(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductController::injected',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ttl          20s', $tester->getDisplay());
        self::assertStringContainsString('tags         inventory, product', $tester->getDisplay());
        self::assertStringContainsString('InventoryQuery::execute', $tester->getDisplay());
    }

    public function testAnalyzeComposesAnUncachedControllerAction(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductController::show',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('ProductController::show (uncached entry point)', $output);
        self::assertStringContainsString('ttl          20s', $output);
        self::assertStringContainsString('private (restricted by ViewerQuery::execute)', $output);
        self::assertStringContainsString('tags         inventory, product, viewer', $output);
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
        self::assertSame('private', $data['effective']['visibility']);
        self::assertFalse($data['effective']['storable']);
        self::assertSame([], $data['effective']['problems']);
        self::assertIsArray($data['dependencies']);
        self::assertCount(3, $data['dependencies']);
        self::assertStringContainsString('ProductController::show (uncached entry point)<br/>20s - private', $mermaid->getDisplay());
    }

    public function testAnalyzeFollowsAnUncachedMethodCallingAnotherUncachedMethod(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));

        $tester->execute([
            'boundary' => 'ProductController::index',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('ttl          20s', $tester->getDisplay());
        self::assertStringContainsString('ProductController::show (uncached)', $tester->getDisplay());
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
        self::assertStringContainsString('20s (declared 120s, capped by ProductQuery::execute)', $tester->getDisplay());
        self::assertStringContainsString('private (restricted by ViewerQuery::execute)', $tester->getDisplay());
        self::assertStringContainsString('$productId, $viewerId (ignored: $trace)', $tester->getDisplay());
        self::assertStringContainsString('InventoryQuery::execute', $tester->getDisplay());
    }

    public function testAnalyzeRendersJsonAndMermaidOutput(): void
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
        self::assertSame('unknown', $baseline['effective']['ttl']['state']);
        self::assertSame(30, $baseline['effective']['ttl']['upperBound']);
        self::assertFalse($baseline['effective']['storable']);
        self::assertTrue($baseline['effective']['visibilityUnknown']);
        self::assertTrue($baseline['effective']['tagsUnknown']);
        self::assertSame([], $baseline['effective']['problems']);
        self::assertIsArray($baseline['analysisGaps']);
        self::assertIsArray($baseline['analysisGaps'][0]);
        self::assertSame('unverified-cache-propagation', $baseline['analysisGaps'][0]['kind']);
        self::assertSame([InspectionQuery::class.'::execute', InventoryLookup::class.'::get', ProductQuery::class.'::execute'], $baseline['analysisGaps'][0]['path']);

        $tester->execute([...$arguments, '--show-uncached' => true]);
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

        $tester->execute([...$arguments, '--show-uncached' => true, '--ignore' => ['*Lookup', 'ViewerQuery']]);
        $filtered = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($filtered);
        self::assertSame($expanded['effective'], $filtered['effective']);
        self::assertSame($expanded['analysisGaps'], $filtered['analysisGaps']);
        self::assertIsArray($filtered['dependencies']);
        self::assertSame([InspectionQuery::class.'::offset'], array_column($filtered['dependencies'], 'boundary'));
    }

    #[DataProvider('providerFormatsAndFlags')]
    public function testCacheGapsAreVisibleWithoutOrdinaryCallInspection(string $format, bool $showUncached): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'InspectionQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
            '--format' => $format,
            '--show-uncached' => $showUncached,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('cache propagation unanalyzed:', $tester->getDisplay());
        self::assertStringContainsString('InventoryLookup::get', $tester->getDisplay());
        self::assertStringContainsString('ProductQuery::execute', $tester->getDisplay());
    }

    #[DataProvider('providerFormatsAndFlags')]
    public function testIgnorePrunesCachedSubtreesIndependentlyOfShowUncached(string $format, bool $showUncached): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'BubblingPageQuery::explicit',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => $format,
            '--show-uncached' => $showUncached,
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

        $arguments = ['boundary' => 'InspectionQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture'], '--format' => $format, '--show-uncached' => true];
        $tester->execute($arguments);
        self::assertStringContainsString('InventoryLookup::get (uncached)', $tester->getDisplay());
        self::assertStringContainsString('InspectionQuery::offset (uncached)', $tester->getDisplay());
        self::assertStringContainsString('ProductQuery::execute', $tester->getDisplay());

        $tester->execute([...$arguments, '--ignore' => ['*Lookup']]);
        self::assertStringNotContainsString('InventoryLookup::get (uncached)', $tester->getDisplay());
        self::assertStringNotContainsString('ProductQuery::execute  ttl', $tester->getDisplay());
        self::assertStringNotContainsString('ProductQuery::execute<br/>', $tester->getDisplay());
        self::assertStringContainsString('cache propagation unanalyzed:', $tester->getDisplay());
        self::assertStringContainsString('InspectionQuery::offset (uncached)', $tester->getDisplay());
    }

    #[DataProvider('providerFormatsAndFlags')]
    public function testIgnoreAlsoAppliesToTheSelectedRoot(string $format, bool $showUncached): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'ProductController::show',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
            '--format' => $format,
            '--show-uncached' => $showUncached,
            '--ignore' => ['*Controller'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame($format === 'json' ? '[]' : 'All matching roots were excluded by --ignore.', trim($tester->getDisplay()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providerFormatsAndFlags(): iterable
    {
        foreach (['tree', 'json', 'mermaid'] as $format) {
            foreach ([false, true] as $flag) {
                yield $format.($flag ? ' expanded' : ' default') => [$format, $flag];
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
    public function testAnalyzePreservesNestedCandidatesAndFiniteProofAcrossDifferentTimezones(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $arguments = ['boundary' => 'NoonQuery::composed', '--path' => ['packages/magix-cache-cli/tests/Fixture/Expiration']];
        $tester->execute([...$arguments, '--format' => 'json']);
        $tester->assertCommandIsSuccessful();
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['strategy']);
        self::assertIsArray($data['strategy']['expirations']);
        self::assertCount(2, $data['strategy']['expirations']);
        self::assertIsArray($data['strategy']['steps']);
        self::assertIsArray($data['strategy']['steps'][0]);
        self::assertArrayHasKey('expirations', $data['strategy']['steps'][0]);
        self::assertIsArray($data['effective']);
        self::assertIsArray($data['effective']['ttl']);
        self::assertSame(60, $data['effective']['ttl']['upperBound']);
        self::assertNull($data['effective']['ttl']['lowerBound']);
        self::assertTrue($data['effective']['ttl']['finite']);
        self::assertSame([], $data['effective']['problems']);

        $tester->execute([...$arguments, '--format' => 'mermaid']);
        self::assertStringContainsString('earliest of (daily 12:00-12:15 Asia/Tokyo; daily 09:00 America/New_York)', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function providerExpirationPolicies(): iterable
    {
        yield 'auto' => ['automatic', null];
        yield 'fixed' => ['fixed', 60];
        yield 'bounded' => ['bounded', 30];
        yield 'uncached entry' => ['show', 30];
    }
}
