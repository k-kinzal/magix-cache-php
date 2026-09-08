<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Console;

use JsonException;
use Magix\Cache\Cli\Console\AnalyzeCommand;
use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Console\CatalogLoader;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AnalyzeCommand::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyArgumentBinder::class)]
#[UsesClass(\Magix\Cache\Metadata\Visibility::class)]
#[UsesNamespace('Magix\Cache\Runtime\Parameter')]
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
        self::assertStringContainsString("\033[32m60s\033[39m", $tester->getDisplay());
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
        self::assertStringContainsString('ProductController::show (uncached entry point)', $tester->getDisplay());
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
}
