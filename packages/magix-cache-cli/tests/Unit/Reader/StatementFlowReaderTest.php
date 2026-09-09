<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Reader\StatementFlowReader;
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
        self::assertNotEmpty($node->analysisWarnings);
    }
    public function testWritesDropsUnsupportedReferenceAssignments(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->a(); $alias =& $v; $alias = $this->inputs->b(); return $v;');
        self::assertNotEmpty($node->analysisWarnings);
        self::assertNull($node->effect->ttl->seconds);
    }

}
