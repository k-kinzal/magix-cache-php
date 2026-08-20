<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\MissingPolicyRule;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingPolicyRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(PolicyDeclaration::class)]
final class MissingPolicyRuleTest extends TestCase
{
    public function testCheckReportsABoundaryWithoutAnyPolicy(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(),
        );

        $diagnostics = (new MissingPolicyRule())->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame('missing-policy', $diagnostics[0]->rule);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
        self::assertSame(31, $diagnostics[0]->line);
    }

    public function testCheckAcceptsADeclaredPolicy(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                policy: new PolicyDeclaration(PolicySource::MethodAttribute, 20),
            ),
            new CacheEffect(ttl: 20),
        );

        self::assertSame([], (new MissingPolicyRule())->check($node, new Catalog([])));
    }
}
