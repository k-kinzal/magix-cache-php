<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\KeyParameter;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\ScopedIgnoreConflictRule;
use Magix\Cache\Cli\Lint\Severity;
use Magix\Cache\Runtime\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopedIgnoreConflictRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(KeyParameter::class)]
final class ScopedIgnoreConflictRuleTest extends TestCase
{
    public function testCheckReportsAnIgnoredParameterThatIsAlsoScoped(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                parameters: [new KeyParameter('viewerId', ignored: true, scope: Visibility::Private)],
            ),
            new CacheEffect(),
        );

        $diagnostics = (new ScopedIgnoreConflictRule())->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
        self::assertStringContainsString('$viewerId', $diagnostics[0]->message);
    }

    public function testCheckAcceptsAnIgnoredParameterScopedToNoStore(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                parameters: [new KeyParameter('load', ignored: true, scope: Visibility::NoStore)],
            ),
            new CacheEffect(),
        );

        self::assertSame([], (new ScopedIgnoreConflictRule())->check($node, new Catalog([])));
    }
}
