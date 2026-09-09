<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use JsonException;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheGap::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(KeyParameter::class)]
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
            ['source' => 'MethodAttribute', 'ttl' => '120s', 'maxTtl' => null, 'tags' => ['page'], 'visibility' => null, 'version' => '1', 'runtime' => 'default'],
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
}
