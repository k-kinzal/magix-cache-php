<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Reader\ExpressionFlowReader;
use Magix\Cache\Cli\Reader\StatementFlowReader;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\AnalysisSource;

#[CoversClass(StatementFlowReader::class)]
#[UsesNamespace('Magix\Cache')]
final class StatementFlowReaderTest extends TestCase
{
    public function testReadKeepsAliasesOfTheSameBranchCorrelated(): void
    {
        $node = AnalysisSource::node('$v = $flag ? $this->inputs->a() : $this->inputs->b(); $alias = $v; return $v->zip($alias);');
        self::assertNotNull($node->metadataVariants);
        self::assertCount(2, $node->metadataVariants);
        self::assertSame(['a'], $node->metadataVariants[0]->effect->tags);
        self::assertSame(['b'], $node->metadataVariants[1]->effect->tags);
    }

    public function testBranchesKeepTheImplicitElseAndExcludeThrownPaths(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->a(); if ($flag === 1) { $v = $this->inputs->b(); } if ($flag === 2) { throw new RuntimeException(); } return $v;');
        self::assertSame('20/60s', $node->effect->ttl->label());
        self::assertNotNull($node->metadataVariants);
        self::assertCount(2, $node->metadataVariants);
    }

    public function testCasesRetainNoMatchWhenSwitchHasNoDefault(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->a(); switch ($flag) { case 1: $v = $this->inputs->b(); break; } return $v;');
        self::assertSame('20/60s', $node->effect->ttl->label());
    }

    public function testConditionWritesNeverReusesABindingChangedByAnOpaqueExpression(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->a(); if (($v = $this->inputs->b())->value()) {} return $v;');
        self::assertSame(TtlEstimateState::Unknown, $node->effect->ttl->state);
        self::assertNotEmpty($node->effect->analysis->causes());
    }
    public function testWritesDropsUnsupportedReferenceAssignments(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->a(); $alias =& $v; $alias = $this->inputs->b(); return $v;');
        self::assertNotEmpty($node->effect->analysis->causes());
        self::assertNull($node->effect->ttl->seconds);
    }

    public function testAffectsIgnoresAStatementNothingLaterReads(): void
    {
        $node = AnalysisSource::node('if ($flag === 1) { $unused = 1; } foreach ([1, 2] as $i) { $seen = $i; } return $this->inputs->a();');

        self::assertSame('20s', $node->effect->ttl->label());
        self::assertSame([], $node->effect->analysis->causes());
    }

    public function testExpressionBindsASimpleAssignmentAndDropsAnythingElse(): void
    {
        $reader = new StatementFlowReader(new ExpressionFlowReader('Root', [], []));
        $bound = $reader->expression(new Assign(new Variable('value'), new Int_(1)), []);

        self::assertArrayHasKey('value', $bound);
        self::assertSame([], $reader->expression(new Assign(new Variable('value'), new Int_(2)), $bound)['dropped'] ?? []);
    }

    public function testReturnsIgnoresAReturnThatBelongsToANestedFunction(): void
    {
        $node = AnalysisSource::node('$make = function () { return 1; }; return $this->inputs->a();');

        self::assertSame('20s', $node->effect->ttl->label());
    }

    public function testInsideComparesSourceSpans(): void
    {
        $reader = new StatementFlowReader(new ExpressionFlowReader('Root', [], []));
        $outer = AnalysisSource::statement('if (true) { $a = 1; }');
        $inner = AnalysisSource::statement('$a = 1;');

        self::assertTrue($reader->inside($outer, [$outer]));
        self::assertFalse($reader->inside($outer, [$inner]));
    }

    public function testForkOpensTheAlternativesAReturningBranchAllows(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->a(); if ($flag === 1) { return $this->inputs->b(); } return $v;');

        self::assertSame('20/60s', $node->effect->ttl->label());
        self::assertSame([], $node->effect->problems);
    }

}
