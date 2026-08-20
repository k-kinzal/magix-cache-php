<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\UnstableKeyArgumentRule;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnstableKeyArgumentRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(KeyParameter::class)]
final class UnstableKeyArgumentRuleTest extends TestCase
{
    public function testCheckReportsKeyParametersThatCannotBeHashed(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                parameters: [
                    new KeyParameter('filter', type: 'object'),
                    new KeyParameter('load', type: 'Closure'),
                    new KeyParameter('untyped'),
                ],
            ),
            new CacheEffect(),
        );

        $diagnostics = (new UnstableKeyArgumentRule())->check($node, new Catalog([]));

        self::assertCount(3, $diagnostics);
        self::assertSame(Severity::Warning, $diagnostics[0]->severity);
        self::assertSame('unstable-key-argument', $diagnostics[0]->rule);
    }

    public function testCheckAcceptsScalarIgnoredAndReducedParameters(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                parameters: [
                    new KeyParameter('productId', type: 'int'),
                    new KeyParameter('trace', type: 'object', ignored: true),
                    new KeyParameter('request', type: 'object', reducer: 'App\Reducer::of'),
                ],
            ),
            new CacheEffect(),
        );

        self::assertSame([], (new UnstableKeyArgumentRule())->check($node, new Catalog([])));
    }
}
