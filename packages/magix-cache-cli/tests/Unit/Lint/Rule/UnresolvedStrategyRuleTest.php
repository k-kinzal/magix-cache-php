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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnresolvedStrategyRule::class)]
#[UsesNamespace('Magix\Cache\Cli')]
#[UsesClass(BoundaryDeclaration::class)]
#[UsesClass(CacheEffect::class)]
#[UsesClass(CacheNode::class)]
#[UsesClass(Catalog::class)]
#[UsesClass(Diagnostic::class)]
#[UsesClass(StrategyEffect::class)]
#[UsesClass(TtlEstimate::class)]
#[UsesClass(UseStrategyDeclaration::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlInterval::class)]
#[UsesClass(\Magix\Cache\Cli\Graph\TtlRangeSet::class)]
final class UnresolvedStrategyRuleTest extends TestCase
{
    #[DataProvider('providerInvalidAlternativeContracts')]
    public function testCheckReportsAnInvalidAlternativeContract(\Magix\Cache\Cli\Declaration\TtlContract $contract): void
    {
        $strategy = new \Magix\Cache\Cli\Declaration\StrategyDeclaration(
            'Timed',
            hasCreate: true,
            composed: [new \Magix\Cache\Cli\Declaration\StrategyInstantiation('Timed')],
            ttl: $contract,
        );
        $catalog = new Catalog([new \Magix\Cache\Cli\Declaration\ClassDeclaration('Timed', strategy: $strategy)]);
        $boundary = new BoundaryDeclaration('Page', 'fetch', 'page.php', 1, useStrategy: new UseStrategyDeclaration('Timed'));
        $effect = (new \Magix\Cache\Cli\Graph\StrategyResolver($catalog))->resolve($boundary);
        self::assertNotNull($effect);
        $diagnostics = (new UnresolvedStrategyRule())->check(new CacheNode($boundary, new CacheEffect(strategy: $effect)), $catalog);

        self::assertNotEmpty($diagnostics);
        self::assertSame('unresolved-strategy', $diagnostics[0]->rule);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
    }

    /**
     * @return iterable<string, array{\Magix\Cache\Cli\Declaration\TtlContract}>
     */
    public static function providerInvalidAlternativeContracts(): iterable
    {
        yield 'empty' => [new \Magix\Cache\Cli\Declaration\TtlContract(oneOf: [])];
        yield 'mixed' => [new \Magix\Cache\Cli\Declaration\TtlContract(min: 30, oneOf: [new \Magix\Cache\Cli\Declaration\TtlContract(600, 900)])];
        yield 'negative' => [new \Magix\Cache\Cli\Declaration\TtlContract(oneOf: [new \Magix\Cache\Cli\Declaration\TtlContract(-1, -1)])];
        yield 'missing reference' => [new \Magix\Cache\Cli\Declaration\TtlContract(oneOf: [new \Magix\Cache\Cli\Declaration\TtlContract(
            new \Magix\Cache\Cli\Declaration\ContractReference(\Magix\Cache\Cli\Declaration\ContractSource::Constructor, 'missing'),
            900,
        )])];
    }

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
