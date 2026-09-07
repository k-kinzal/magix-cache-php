<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Lint\Rule;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Declaration\UseStrategyDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\Rule\UnresolvedStrategyRule;
use Magix\Cache\Cli\Lint\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnresolvedStrategyRule::class)]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(StrategyEffect::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(UseStrategyDeclaration::class)]
final class UnresolvedStrategyRuleTest extends TestCase
{
    public function testCheckReportsEveryStrategyDeclarationProblem(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration(
                class: 'App\PageQuery',
                method: 'execute',
                file: 'src/PageQuery.php',
                line: 31,
                useStrategy: new UseStrategyDeclaration('App\X', [], 12),
            ),
            new CacheEffect(strategy: new StrategyEffect(
                label: 'X::create()',
                ttl: TtlEstimate::invalid('problem text'),
                problems: ['problem text'],
            )),
        );

        $diagnostics = (new UnresolvedStrategyRule())->check($node, new Catalog([]));

        self::assertCount(1, $diagnostics);
        self::assertSame('unresolved-strategy', $diagnostics[0]->rule);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
        self::assertSame('App\PageQuery::execute', $diagnostics[0]->boundary);
        self::assertSame('src/PageQuery.php', $diagnostics[0]->file);
        self::assertSame(12, $diagnostics[0]->line);
        self::assertSame('problem text.', $diagnostics[0]->message);
    }

    public function testCheckIgnoresABoundaryWithoutAStrategyEffect(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(),
        );

        self::assertSame([], (new UnresolvedStrategyRule())->check($node, new Catalog([])));
    }

    public function testCheckAcceptsAStrategyEffectWithoutProblems(): void
    {
        $node = new CacheNode(
            new BoundaryDeclaration('App\PageQuery', 'execute', 'src/PageQuery.php', 31),
            new CacheEffect(strategy: new StrategyEffect('X::create()', TtlEstimate::known(60))),
        );

        self::assertSame([], (new UnresolvedStrategyRule())->check($node, new Catalog([])));
    }
}
