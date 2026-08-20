<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Render\JsonRenderer;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(PolicyDeclaration::class)]
final class JsonRendererTest extends TestCase
{
    public function testRenderEncodesASingleTreeAsAnObject(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: 20, storable: true),
        );

        $json = (new JsonRenderer())->render([$node]);

        self::assertJson($json);
        self::assertStringContainsString('"boundary": "App\\\\ProductQuery::execute"', $json);
    }

    public function testTreeDescribesPolicyKeyAndDependencies(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: 20, storable: true),
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
            new CacheEffect(ttl: 20, visibility: Visibility::Private, storable: true, tags: ['page']),
            [$child],
        );

        $tree = (new JsonRenderer())->tree($node);

        self::assertSame('App\PageQuery::execute', $tree['boundary']);
        self::assertSame(['source' => 'MethodAttribute', 'ttl' => '120s', 'maxTtl' => null, 'tags' => ['page'], 'visibility' => 'shared', 'clamp' => true, 'version' => '1'], $tree['policy']);
        self::assertSame([['name' => 'viewerId', 'type' => 'int', 'ignored' => false, 'scope' => 'private', 'reducer' => null]], $tree['key']);
        self::assertArrayHasKey('effective', $tree);
        self::assertArrayHasKey('dependencies', $tree);
        self::assertStringContainsString('"ttl": 20', (new JsonRenderer())->render([$node]));
    }
}
