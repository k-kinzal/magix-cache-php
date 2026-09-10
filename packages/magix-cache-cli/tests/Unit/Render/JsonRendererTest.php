<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use JsonException;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\MetadataContract;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheGap;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\StrategyStep;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\JsonRenderer;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonRenderer::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Magix\Cache')]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheGap::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(MetadataContract::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(StrategyEffect::class)]
#[UsesClass(StrategyStep::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class JsonRendererTest extends TestCase
{
    public function testGapKeepsQualifiedEndpointsAndIntermediateMethods(): void
    {
        $gap = new CacheGap([
            new BoundaryDeclaration('App\PageQuery', 'get', 'page.php', 1),
            new BoundaryDeclaration('App\Lookup', 'get', 'lookup.php', 2, isCacheBoundary: false),
            new BoundaryDeclaration('Other\PageQuery', 'get', 'other.php', 3),
        ]);

        $data = (new JsonRenderer())->gap($gap);

        self::assertSame('unverified-cache-propagation', $data['kind']);
        self::assertSame(['App\PageQuery::get', 'App\Lookup::get', 'Other\PageQuery::get'], $data['path']);
        self::assertSame('cache propagation unanalyzed: PageQuery::get -> Lookup::get -> PageQuery::get', $data['message']);
    }

    /**
     * @throws JsonException
     */
    public function testRenderEncodesASingleTreeAsAnObject(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::known(20), storable: true),
        );

        $json = (new JsonRenderer())->render([$node]);

        self::assertJson($json);
        self::assertStringContainsString('"boundary": "App\\\\ProductQuery::execute"', $json);
        self::assertStringContainsString('"state": "known"', $json);
        self::assertStringContainsString('"seconds": 20', $json);
    }

    public function testTreeDescribesPolicyKeyAndDependencies(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::known(20), storable: true),
        );
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, 120, tags: ['page']),
                parameters: [new KeyParameter('viewerId', type: 'int', scope: Visibility::Private)],
            ),
            new CacheEffect(
                ttl: TtlEstimate::known(20, 'declared 120s, capped by ProductQuery::execute'),
                visibility: Visibility::Private,
                storable: true,
                tags: ['page'],
            ),
            [$child],
        );

        $tree = (new JsonRenderer())->tree($node);

        self::assertSame('App\PageQuery::execute', $tree['boundary']);
        self::assertSame(
            ['source' => 'MethodAttribute', 'ttl' => '120s', 'ttlUnknown' => false, 'tagsUnknown' => false, 'visibilityUnknown' => false, 'maxTtl' => null, 'maxTtlUnknown' => false, 'tags' => ['page'], 'visibility' => null, 'version' => '1', 'runtime' => 'default'],
            $tree['policy'],
        );
        self::assertSame([['name' => 'viewerId', 'type' => 'int', 'ignored' => false, 'scope' => 'private', 'reducer' => null, 'configuration' => null]], $tree['key']);
        self::assertArrayHasKey('effective', $tree);
        self::assertArrayHasKey('dependencies', $tree);
    }

    /**
     * @throws JsonException
     */
    public function testTreeEncodesTheEstimateAsAStructuredObject(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\RateQuery', 'execute', 'src/RateQuery.php', 12),
            new CacheEffect(ttl: TtlEstimate::unknown(30, 'requires a finite upstream expiration at runtime')),
        );

        $tree = (new JsonRenderer())->tree($node);
        $effective = $tree['effective'];

        self::assertIsArray($effective);
        self::assertSame(
            ['state' => 'unknown', 'seconds' => null, 'lowerBound' => null, 'upperBound' => 30, 'reason' => 'requires a finite upstream expiration at runtime'],
            $effective['ttl'] ?? null,
        );
        self::assertStringContainsString('"upperBound": 30', (new JsonRenderer())->render([$node]));
    }

    public function testStrategyEncodesTheCompositionAndItsSteps(): void
    {
        $strategy = new StrategyEffect(
            label: 'ProductCacheStrategy::create(min: 30)',
            ttl: TtlEstimate::unknown(60, null, 30),
            steps: [new StrategyStep('App\\Spread', TtlEstimate::unknown(60, null, 30), assumed: true)],
            overridesExpiration: true,
            problems: [],
        );

        $encoded = (new JsonRenderer())->strategy($strategy);

        self::assertSame('ProductCacheStrategy::create(min: 30)', $encoded['declared']);
        self::assertSame(true, $encoded['overridesExpiration']);
        self::assertSame(
            ['state' => 'unknown', 'seconds' => null, 'lowerBound' => 30, 'upperBound' => 60, 'reason' => null],
            $encoded['ttl'],
        );
        self::assertSame(
            [['strategy' => 'App\\Spread', 'ttl' => TtlEstimate::unknown(60, null, 30)->jsonSerialize(), 'assumed' => true]],
            $encoded['steps'],
        );
    }

    public function testPolicyReportsAnUnreadableFieldInsteadOfItsDefault(): void
    {
        $declared = new PolicyDeclaration(PolicySource::MethodAttribute, ttl: 120, version: 'v7');
        $unreadable = new PolicyDeclaration(
            PolicySource::MethodAttribute,
            ttl: Ttl::FromUpstream,
            maxTtlUnknown: true,
            versionUnknown: true,
        );

        $renderer = new JsonRenderer();

        self::assertSame('v7', $renderer->policy($declared)['version']);
        self::assertFalse($renderer->policy($declared)['maxTtlUnknown']);
        self::assertNull($renderer->policy($unreadable)['version']);
        self::assertTrue($renderer->policy($unreadable)['maxTtlUnknown']);
    }

    public function testEffectRetainsDeterminedTtlWhileOtherFieldsRemainPartial(): void
    {
        $node = \Tests\Package\Cli\Fixture\ReportSource::node('Page::fixed');
        $data = (new JsonRenderer())->effect($node);
        self::assertSame(TtlEstimate::known(60, $node->effect->ttl->reason)->jsonSerialize(), $data['ttl']);
        self::assertSame(['ttl' => 'known', 'visibility' => 'partial', 'tags' => 'partial'], $data['certainty']);
        self::assertSame('unknown', $data['storage']);
        self::assertSame($node->effect->analysis, $data['analysis']);
    }

    /**
     * @throws JsonException
     */
    public function testRenderResolvesEveryHiddenCauseWithoutRestoringHiddenRows(): void
    {
        $node = \Tests\Package\Cli\Fixture\ReportSource::node('Page::multiple');
        $nodes = (new \Magix\Cache\Cli\Render\TreeFilter(uncached: \Magix\Cache\Cli\Render\UncachedMode::None))->apply($node);
        $report = json_decode((new JsonRenderer())->render($nodes), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['diagnostics']);
        self::assertCount(1, $report['diagnostics']);
        self::assertIsArray($report['diagnostics'][0]);
        self::assertSame('Bridge::get', $report['diagnostics'][0]['method']);
        self::assertSame(array_keys($node->effect->analysis->tags)[0], $report['diagnostics'][0]['id']);
        self::assertStringNotContainsString('"boundary": "Bridge::get"', (new JsonRenderer())->render($nodes));
    }

    public function testTreeRetainsMigrationDeclarationsSeparatelyFromReturnedMetadata(): void
    {
        $node = \Tests\Package\Cli\Fixture\ReportSource::node('Migration::get');
        $data = (new JsonRenderer())->tree($node);
        self::assertTrue($data['declared']);
        self::assertSame('not-observed', $data['execution']);
        self::assertSame('Magix\\Cache\\Cached', $data['returnType']);
        self::assertIsArray($data['policy']);
        self::assertSame('60s', $data['policy']['ttl']);
        self::assertSame($node->boundary->parameters, $data['parameters']);
        self::assertNull($data['key']);
        self::assertSame(10, $node->effect->ttl->seconds);
        self::assertFalse($node->effect->storable);
    }

    /**
     * @throws JsonException
     */
    public function testRenderPreservesUnreadableArgumentsWithoutLosingMigrationResults(): void
    {
        $node = \Tests\Package\Cli\Fixture\ReportSource::node('Migration::get');
        $report = \Tests\Package\Cli\Fixture\JsonReport::root((new JsonRenderer())->render([$node]));
        self::assertIsArray($report['useStrategy']);
        self::assertSame(['limit' => ['state' => 'unknown']], $report['useStrategy']['arguments']);
        self::assertIsArray($report['parameters']);
        self::assertIsArray($report['parameters'][0]);
        self::assertSame('ttl', $report['parameters'][0]['name']);
        self::assertIsArray($report['parameters'][0]['configuration']);
        self::assertTrue($report['parameters'][0]['configuration']['ttl']);
        self::assertIsArray($report['effective']);
        self::assertIsArray($report['effective']['ttl']);
        self::assertSame(10, $report['effective']['ttl']['seconds']);
        self::assertSame('not-observed', $report['execution']);
    }

    /**
     * @throws JsonException
     */
    public function testRenderKeepsMetadataReferencesSeparateFromEffectiveConstraints(): void
    {
        $node = \Tests\Package\Cli\Fixture\AnalysisSource::node('return opaque($this->inputs->b());', '#[Cache(ttl: 120)]');
        $nodes = (new \Magix\Cache\Cli\Render\TreeFilter(uncached: \Magix\Cache\Cli\Render\UncachedMode::None))->apply($node);
        $report = \Tests\Package\Cli\Fixture\JsonReport::root((new JsonRenderer())->render($nodes));
        self::assertIsArray($report['effective']);
        $effect = $report['effective'];
        self::assertSame('shared', $effect['visibility']);
        self::assertTrue($effect['visibilityUnknown']);
        self::assertSame([], $effect['tags']);
        self::assertTrue($effect['tagsUnknown']);
        self::assertFalse($effect['storable']);
        self::assertSame('unknown', $effect['storage']);
        self::assertIsArray($effect['analysis']);
        self::assertIsArray($effect['analysis']['visibilityReference']);
        self::assertSame('private', $effect['analysis']['visibilityReference']['value']);
        self::assertSame(['Inputs::b'], $effect['analysis']['visibilityReference']['sources']);
        self::assertIsArray($effect['analysis']['tagsReference']);
        self::assertSame(['b'], $effect['analysis']['tagsReference']['value']);
        self::assertSame(['ttl' => 'known', 'visibility' => 'partial', 'tags' => 'partial'], $effect['certainty']);
    }
}
