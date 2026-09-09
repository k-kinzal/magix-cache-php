<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Console\Application;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\StrategyStep;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\TreeRenderer;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TreeRenderer::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(\Magix\Cache\Runtime\CacheKeyArgumentBinder::class)]
#[UsesClass(Visibility::class)]
#[UsesNamespace('Magix\Cache\Runtime\Parameter')]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(StrategyEffect::class)]
#[UsesClass(StrategyStep::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class TreeRendererTest extends TestCase
{
    public function testRestrictedHighlightsLocalCapsAndKeepsUnrestrictedFields(): void
    {
        $renderer = new TreeRenderer();
        $effect = new CacheEffect(ttl: TtlEstimate::known(20), storable: true, localRestrictions: ['ttl' => 'local ttl 20s; composed 60s']);

        self::assertSame(
            '<fg=yellow>20s</>',
            $renderer->restricted($renderer->estimate($effect->ttl), $effect, 'ttl'),
        );
        self::assertSame('shared', $renderer->restricted('shared', $effect, 'visibility'));
    }

    public function testRenderShowsTheEffectiveValuesOfTheRootBoundary(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120, tags: ['page']),
                parameters: [new KeyParameter('productId'), new KeyParameter('trace', ignored: true)],
            ),
            new CacheEffect(
                ttl: TtlEstimate::known(20, 'declared 120s, capped by ProductQuery::execute'),
                visibility: Visibility::Private,
                storable: true,
                tags: ['page', 'product'],
            ),
        );

        $report = (new TreeRenderer())->render($node);

        self::assertStringContainsString('App\PageQuery::execute', $report);
        self::assertStringContainsString('src/PageQuery.php:31', $report);
        self::assertStringContainsString('declared 120s, capped by ProductQuery::execute', $report);
        self::assertStringContainsString('private', $report);
        self::assertStringContainsString('storable     yes', $report);
        self::assertStringContainsString('page, product', $report);
    }

    public function testLinesDrawChildrenProblemsAndNotes(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::known(20), storable: true),
            [],
            ['recursive dependency, not expanded again'],
        );
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(ttl: TtlEstimate::invalid('no #[Cache] attribute'), problems: ['no #[Cache] attribute']),
            [$child],
        );

        $lines = (new TreeRenderer())->lines($node);

        self::assertStringContainsString('PageQuery::execute', $lines[0]);
        self::assertStringContainsString('no #[Cache] attribute', $lines[1]);
        self::assertStringContainsString('`-- ', $lines[2]);
        self::assertStringContainsString('recursive dependency', $lines[3]);
    }

    public function testSummaryNamesTheDeclaredTtlWhenItDiffers(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120),
            ),
            new CacheEffect(ttl: TtlEstimate::known(20), tags: ['page']),
        );

        $summary = (new TreeRenderer())->summary($node);

        self::assertStringContainsString('PageQuery::execute', $summary);
        self::assertStringContainsString('20s', $summary);
        self::assertStringContainsString('(declared 120s)', $summary);
        self::assertStringContainsString('tags page', $summary);
    }

    public function testTtlDescribesEveryEstimateState(): void
    {
        $renderer = new TreeRenderer();

        self::assertStringContainsString('unconstrained', $renderer->ttl(new CacheEffect(ttl: TtlEstimate::unconstrained())));
        self::assertStringContainsString('30s', $renderer->ttl(new CacheEffect(ttl: TtlEstimate::known(30))));
        self::assertStringContainsString(
            '(inherited from A::b)',
            $renderer->ttl(new CacheEffect(ttl: TtlEstimate::known(30, 'inherited from A::b'))),
        );
        self::assertSame(
            '≤30s (requires a finite upstream expiration at runtime)',
            $renderer->ttl(new CacheEffect(ttl: TtlEstimate::unknown(30, 'requires a finite upstream expiration at runtime'))),
        );
    }

    public function testStrategyRowsKeepCandidateAndEffectiveApart(): void
    {
        $renderer = new TreeRenderer();
        $effect = new CacheEffect(
            ttl: TtlEstimate::unknown(60, 'an upstream expiration may shorten the lifetime'),
            strategy: new StrategyEffect(
                label: 'ProductCacheStrategy::create(min: 30)',
                ttl: TtlEstimate::unknown(60, null, 30),
                steps: [
                    new StrategyStep('App\\KeySpreadExpirationStrategy', TtlEstimate::unknown(60, null, 30)),
                    new StrategyStep('App\\ExternalStrategy', TtlEstimate::unknown(300, null, 30), assumed: true),
                ],
            ),
        );

        self::assertSame(
            [
                '  strategy     ProductCacheStrategy::create(min: 30)',
                '               - KeySpreadExpirationStrategy  30-60s',
                '               - ExternalStrategy  30-300s (assumed)',
                '  strategy ttl 30-60s',
            ],
            $renderer->strategy($effect),
        );
    }

    public function testStrategyRowsAreAbsentWithoutADeclaredStrategy(): void
    {
        self::assertSame([], (new TreeRenderer())->strategy(new CacheEffect()));
    }

    public function testLabelledAppendsTheReasonOrCondition(): void
    {
        $renderer = new TreeRenderer();

        self::assertSame('30-60s', $renderer->labelled(TtlEstimate::unknown(60, null, 30)));
        self::assertSame(
            '20s (inherited from A::b)',
            $renderer->labelled(TtlEstimate::known(20, 'inherited from A::b')),
        );
    }

    public function testEstimateOnlyAddsColorForInvalidLifetimes(): void
    {
        $renderer = new TreeRenderer();

        self::assertSame('30s', $renderer->estimate(TtlEstimate::known(30)));
        self::assertSame('<fg=red>invalid</>', $renderer->estimate(TtlEstimate::invalid('broken')));
        self::assertSame('unknown', $renderer->estimate(TtlEstimate::unknown()));
    }

    public function testKeyListsKeyedAndIgnoredParameters(): void
    {
        $boundary = new BoundaryDeclaration(
            class: 'App\PageQuery',
            method: 'execute',
            file: 'src/PageQuery.php',
            line: 31,
            policy: new PolicyDeclaration(PolicySource::MethodAttribute, 20, version: '3'),
            parameters: [new KeyParameter('productId'), new KeyParameter('trace', ignored: true)],
        );
        $empty = new BoundaryDeclaration('App\HomeQuery', 'execute', 'src/HomeQuery.php', 5);

        self::assertSame('$productId (ignored: $trace)  version 3', (new TreeRenderer())->key($boundary));
        self::assertSame('class, method and version only  version 1', (new TreeRenderer())->key($empty));
    }

    #[DataProvider('providerBoundaryColors')]
    public function testHighlightFollowsEffectiveStorageInsteadOfDeclaredTtl(string $boundary, int $color): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => $boundary,
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
        ], ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString("\033[".$color.';1m'.$boundary."\033[39;22m", $output);
        self::assertStringNotContainsString("\033[32m", $output);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function providerBoundaryColors(): iterable
    {
        yield 'shared cache' => ['InventoryQuery::execute', 37];
        yield 'private cache' => ['ViewerQuery::execute', 37];
        yield 'automatic class policy' => ['BubblingPageQuery::execute', 37];
        yield 'automatic method policy' => ['BubblingPageQuery::explicit', 37];
        yield 'NoStore policy' => ['NoStorePageQuery::disabled', 90];
        yield 'bubbled NoStore' => ['NoStorePageQuery::execute', 90];
        yield 'zero TTL' => ['NoStorePageQuery::expired', 90];
        yield 'missing policy' => ['BrokenQuery::undeclared', 90];
        yield 'uncached entry point' => ['ProductController::show', 90];
        yield 'dynamic TTL' => ['ExchangeRateQuery::execute', 90];
    }

    public function testNoStoreBubblesGrayToParentsWhileStoredChildrenStayWhite(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'NoStorePageQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
        ], ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString("\033[90;1mNoStorePageQuery::execute\033[39;22m\033[90m  ttl 10s  nostore", $output);
        self::assertStringContainsString("\033[90;1mNoStorePageQuery::disabled\033[39;22m\033[90m  ttl 10s  nostore", $output);
        self::assertStringContainsString("\033[37;1mInventoryQuery::execute\033[39;22m\033[37m  ttl 60s  shared", $output);
        self::assertStringNotContainsString("\033[33m", $output);
    }

    public function testUncachedInspectionPathsAreGrayAndResumeWhiteAtStoredBoundaries(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'InspectionQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
            '--uncached' => 'all',
        ], ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString("\033[90;1mInspectionQuery::execute\033[39;22m", $output);
        self::assertStringContainsString("\033[31m! cache propagation unanalyzed:", $output);
        self::assertStringContainsString("\033[90;1mInventoryLookup::get\033[39;22m\033[90m (uncached)", $output);
        self::assertStringContainsString("\033[90;1mInspectionQuery::offset\033[39;22m\033[90m (uncached)", $output);
        self::assertStringContainsString("\033[37;1mProductQuery::execute\033[39;22m", $output);
    }

    public function testMissingPolicyStaysGrayWithoutHidingItsRedDiagnostic(): void
    {
        $tester = new CommandTester((new Application(dirname(__DIR__, 5)))->console()->find('analyze'));
        $tester->execute([
            'boundary' => 'BrokenQuery::undeclared',
            '--path' => ['packages/magix-cache-cli/tests/Fixture/Project'],
        ], ['decorated' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("\033[90;1mBrokenQuery::undeclared\033[39;22m", $tester->getDisplay());
        self::assertStringContainsString("\033[31m! no #[Cache] attribute", $tester->getDisplay());
    }

    public function testAnsiFlagsPreserveExactlyTheSameText(): void
    {
        $application = (new Application(dirname(__DIR__, 5)))->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $arguments = [
            'command' => 'analyze',
            'boundary' => 'NoStorePageQuery::execute',
            '--path' => ['packages/magix-cache-cli/tests/Fixture'],
        ];

        $tester->run([...$arguments, '--ansi' => true]);
        $tester->assertCommandIsSuccessful();
        $colored = $tester->getDisplay();
        self::assertStringContainsString("\033[90m", $colored);
        self::assertStringContainsString("\033[37m", $colored);

        $tester->run([...$arguments, '--no-ansi' => true]);
        $tester->assertCommandIsSuccessful();
        $plain = $tester->getDisplay();
        self::assertStringNotContainsString("\033[", $plain);
        self::assertStringNotContainsString('<fg=', $plain);
        self::assertSame($plain, preg_replace('/\x1b\[[0-9;]*m/', '', $colored));
        self::assertStringContainsString('NoStorePageQuery::execute  ttl 10s  nostore', $plain);
    }
}
