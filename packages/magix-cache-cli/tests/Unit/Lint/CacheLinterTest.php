<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\ClassDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Lint\CacheLinter;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\AutoTtlWithoutUpstreamRule;
use Magix\Cache\Cli\Lint\Rule\ClampedTtlRule;
use Magix\Cache\Cli\Lint\Rule\MissingPolicyRule;
use Magix\Cache\Cli\Lint\Rule\ScopedIgnoreConflictRule;
use Magix\Cache\Cli\Lint\Rule\UnscopedPrivateKeyRule;
use Magix\Cache\Cli\Lint\Rule\UnstableKeyArgumentRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheLinter::class)]
#[UsesClass(AutoTtlWithoutUpstreamRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(CacheTree::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(ClampedTtlRule::class)]
#[UsesClass(ClassDeclaration::class)]
#[UsesClass(DependencyConstraint::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(EffectCalculator::class)]
#[UsesClass(MissingPolicyRule::class)]
#[UsesClass(PolicyDeclaration::class)]
#[UsesClass(ScopedIgnoreConflictRule::class)]
#[UsesClass(UnscopedPrivateKeyRule::class)]
#[UsesClass(UnstableKeyArgumentRule::class)]
final class CacheLinterTest extends TestCase
{
    public function testInspectAppliesEveryRuleToEveryBoundary(): void
    {
        $catalog = new Catalog([
            new ClassDeclaration('App\PageQuery', [], [
                new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            ]),
            new ClassDeclaration('App\ProductQuery', [], [
                new BoundaryDeclaration(
                    class: 'App\ProductQuery',
                    method: 'execute',
                    file: 'src/ProductQuery.php',
                    line: 12,
                    policy: new PolicyDeclaration(PolicySource::MethodAttribute, 20),
                ),
            ]),
        ]);

        $diagnostics = (new CacheLinter())->inspect($catalog);

        self::assertCount(1, $diagnostics);
        self::assertSame('missing-policy', $diagnostics[0]->rule);
    }

    public function testInspectRunsOnlyTheRulesItWasGiven(): void
    {
        $catalog = new Catalog([
            new ClassDeclaration('App\PageQuery', [], [
                new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            ]),
        ]);

        self::assertSame([], (new CacheLinter([new ClampedTtlRule()]))->inspect($catalog));
    }
}
