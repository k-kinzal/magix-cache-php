<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\TreeFilter;
use Magix\Cache\Cli\Render\TreeRenderer;
use Magix\Cache\Cli\Render\UncachedMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Tester\ApplicationTester;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(TreeRenderer::class)]
#[UsesNamespace('Magix\Cache')]
#[Medium]
final class TreeRendererTest extends TestCase
{
    public function testRenderKeepsOneLinePerMethodWhenPropagationIsPartial(): void
    {
        $node = (new TreeFilter(uncached: UncachedMode::None))->apply(ReportSource::node('Page::automatic'))[0];
        $report = (new OutputFormatter())->format((new TreeRenderer())->render($node));
        self::assertSame("Page::automatic  ttl 10s?  shared?  tags leaf?\n`-- Leaf::get  ttl 10s  shared  tags leaf\n", $report);
        self::assertStringNotContainsString('Bridge', $report);
        self::assertStringNotContainsString('unanalyzed', $report);
    }

    public function testLinesDoNotGrowWithDiagnosticExplanations(): void
    {
        $node = ReportSource::node('Page::multiple');
        $lines = (new TreeRenderer())->lines($node);
        self::assertCount(7, $lines);
        self::assertStringNotContainsString('Returned metadata', implode("\n", $lines));
        self::assertStringNotContainsString('cache propagation', implode("\n", $lines));
    }

    public function testSummaryShowsDeclarationsWithoutClaimingTheirTtlIsApplied(): void
    {
        $node = ReportSource::node('Migration::get');
        $summary = (new OutputFormatter())->format((new TreeRenderer())->summary($node));
        self::assertSame('Migration::get  ttl 60s [declared]', $summary);
        self::assertSame(10, $node->effect->ttl->seconds);
    }

    public function testFieldHighlightsOnlyExplicitOverridesAndEscapesSourceText(): void
    {
        $renderer = new TreeRenderer();
        $effect = new CacheEffect(TtlEstimate::known(20), localOverrides: ['ttl' => 'policy']);
        self::assertSame('<fg=yellow>20s</>', $renderer->field('20s', $effect, 'ttl'));
        self::assertSame('shared', $renderer->field('shared', $effect, 'visibility'));
        self::assertSame('tag<error>', (new OutputFormatter())->format($renderer->field('tag<error>', $effect, 'tags')));
    }

    public function testHighlightDoesNotColorAncestorsForUnrelatedDescendantLimits(): void
    {
        $node = ReportSource::node('Page::unrelated');
        self::assertSame('<fg=white;options=bold>Page</>', (new TreeRenderer())->highlight('Page', $node, true));
        self::assertSame('yes', $node->storage());
        self::assertNotEmpty($node->children[0]->diagnostics);
    }

    public function testRenderKeepsStructureWhenADeclarationIsInvalid(): void
    {
        $report = (new OutputFormatter())->format((new TreeRenderer())->render(ReportSource::node('Page::invalid')));
        self::assertIsString($report);
        self::assertStringContainsString('ttl invalid', $report);
        self::assertStringContainsString('Leaf::get  ttl 10s', $report);
        self::assertCount(2, explode("\n", trim($report)));
    }

    public function testRenderAnsiFlagsPreserveExactlyTheSameText(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $arguments = ['command' => 'analyze', 'boundary' => 'NoStorePageQuery::execute', '--path' => ['packages/magix-cache-cli/tests/Fixture']];
        $tester->run([...$arguments, '--ansi' => true]);
        $tester->assertCommandIsSuccessful();
        $colored = $tester->getDisplay();
        $tester->run([...$arguments, '--no-ansi' => true]);
        $tester->assertCommandIsSuccessful();
        $plain = $tester->getDisplay();
        self::assertSame($plain, preg_replace('/\x1b\[[0-9;]*m/', '', $colored));
        self::assertStringNotContainsString('<fg=', $plain);
        self::assertStringContainsString('NoStorePageQuery::execute  ttl 10s  nostore', $plain);
    }
}
