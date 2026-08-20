<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Render\TreeRenderer;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TreeRenderer::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(KeyParameter::class)]
#[UsesClass(PolicyDeclaration::class)]
final class TreeRendererTest extends TestCase
{
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
                ttl: 20,
                visibility: Visibility::Private,
                storable: true,
                tags: ['page', 'product'],
                ttlReason: 'declared 120s, clamped by ProductQuery::execute',
            ),
        );

        $report = (new TreeRenderer())->render($node);

        self::assertStringContainsString('App\PageQuery::execute', $report);
        self::assertStringContainsString('src/PageQuery.php:31', $report);
        self::assertStringContainsString('declared 120s, clamped by ProductQuery::execute', $report);
        self::assertStringContainsString('private', $report);
        self::assertStringContainsString('storable     yes', $report);
        self::assertStringContainsString('page, product', $report);
    }

    public function testLinesDrawChildrenProblemsAndNotes(): void
    {
        $child = new CacheNode(
            new BoundaryDeclaration('App\ProductQuery', 'execute', 'src/ProductQuery.php', 12),
            new CacheEffect(ttl: 20, storable: true),
            [],
            ['recursive dependency, not expanded again'],
        );
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(problems: ['no #[Cache] attribute']),
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
            new CacheEffect(ttl: 20, tags: ['page']),
        );

        $summary = (new TreeRenderer())->summary($node);

        self::assertStringContainsString('PageQuery::execute', $summary);
        self::assertStringContainsString('20s', $summary);
        self::assertStringContainsString('(declared 120s)', $summary);
        self::assertStringContainsString('tags page', $summary);
    }

    public function testTtlDescribesMissingExpirations(): void
    {
        $renderer = new TreeRenderer();

        self::assertStringContainsString('none', $renderer->ttl(new CacheEffect()));
        self::assertStringContainsString('30s', $renderer->ttl(new CacheEffect(ttl: 30)));
        self::assertStringContainsString('(inherited from A::b)', $renderer->ttl(new CacheEffect(ttl: 30, ttlReason: 'inherited from A::b')));
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
}
