<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Graph\FlowEffects;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\AnalysisSource;

#[CoversClass(FlowEffects::class)]
#[UsesNamespace('Magix\Cache')]
final class FlowEffectsTest extends TestCase
{
    #[DataProvider('providerBranches')]
    public function testEvaluateKeepsExclusiveChildrenAsCorrelatedAlternatives(string $body, bool $direct): void
    {
        $node = AnalysisSource::node($body, direct: $direct);
        self::assertSame([], $node->gaps);
        self::assertSame([], $node->analysisWarnings);
        self::assertSame([], $node->effect->problems);
        self::assertSame('20/60/90s', $node->effect->ttl->label());
        self::assertSame(TtlEstimateState::Unknown, $node->effect->ttl->state);
        self::assertTrue($node->effect->ttl->hasFiniteExpiration());
        self::assertSame(Visibility::Shared, $node->effect->visibility);
        self::assertTrue($node->effect->visibilityUnknown);
        self::assertSame([], $node->effect->tags);
        self::assertTrue($node->effect->tagsUnknown);
        self::assertNotNull($node->metadataVariants);
        self::assertCount(3, $node->metadataVariants);
        self::assertSame([['a'], ['b'], ['c']], array_map(static fn (CacheVariant $v): array => $v->effect->tags, $node->metadataVariants));
        self::assertSame([Visibility::Shared, Visibility::Private, Visibility::NoStore], array_map(static fn (CacheVariant $v): Visibility => $v->effect->visibility, $node->metadataVariants));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providerBranches(): iterable
    {
        $bodies = [
            'early returns' => 'if ($flag === 0) { return $this->inputs->a(); } if ($flag === 1) { return $this->inputs->b(); } return $this->inputs->c();',
            'if else' => 'if ($flag === 0) { $v = $this->inputs->a(); } elseif ($flag === 1) { $v = $this->inputs->b(); } else { $v = $this->inputs->c(); } return $v;',
            'ternary' => 'return $flag === 0 ? $this->inputs->a() : ($flag === 1 ? $this->inputs->b() : $this->inputs->c());',
            'match' => 'return match ($flag) { 0 => $this->inputs->a(), 1, 2 => $this->inputs->b(), default => $this->inputs->c() };',
            'switch returns' => 'switch ($flag) { case 0: return $this->inputs->a(); case 1: return $this->inputs->b(); default: return $this->inputs->c(); }',
            'switch assignments' => 'switch ($flag) { case 0: $v = $this->inputs->a(); break; case 1: $v = $this->inputs->b(); break; default: $v = $this->inputs->c(); } return $v;',
            'switch fallthrough' => 'switch ($flag) { case 0: case 4: $v = $this->inputs->a(); break; case 1: $v = $this->inputs->b(); break; default: $v = $this->inputs->c(); } return $v;',
            'alias after branch' => '$v = match ($flag) { 0 => $this->inputs->a(), 1 => $this->inputs->b(), default => $this->inputs->c() }; $alias = $v; return $alias->map(fn ($value) => $value);',
        ];

        foreach ($bodies as $name => $body) {
            yield $name.' through ordinary method' => [$body, false];
            yield $name.' inside cached origin' => [$body, true];
        }
    }

    public function testProductComposesEachCombinationWithoutInventingASimultaneousBranch(): void
    {
        $node = AnalysisSource::node('return ($flag ? $this->inputs->a() : $this->inputs->b())->zip($this->inputs->c());');
        self::assertSame('20/60s', $node->effect->ttl->label());
        self::assertNotNull($node->metadataVariants);
        self::assertSame([['a', 'c'], ['b', 'c']], array_map(static fn (CacheVariant $v): array => $v->effect->tags, $node->metadataVariants));
        self::assertSame(Visibility::NoStore, $node->effect->visibility);
    }

    public function testEvaluateDetachesValueAcrossAnUncachedMethod(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->c(); $alias = $v; return $alias->value();', '#[Cache(ttl: 120)]', origin: 'return Cached::of($this->bridge->pick($flag));');
        self::assertSame([], $node->gaps);
        self::assertSame(120, $node->effect->ttl->seconds);
        self::assertSame(Visibility::Shared, $node->effect->visibility);
        self::assertSame([], $node->effect->tags);
        self::assertTrue($node->effect->storable);
        self::assertFalse($node->children[0]->boundary->isCacheBoundary);
        self::assertSame(TtlEstimateState::Unconstrained, $node->children[0]->effect->ttl->state);
        self::assertSame([], $node->children[0]->effect->tags);
        self::assertSame('Inputs::c', $node->children[0]->children[0]->boundary->shortId());
    }

    public function testUnknownRetainsOpaquePropagationAsAGap(): void
    {
        $node = AnalysisSource::node('return transform($this->inputs->a());', '#[Cache(ttl: 120)]');
        self::assertCount(1, $node->gaps);
        self::assertTrue($node->effect->visibilityUnknown);
        self::assertSame([], $node->effect->tags);
        self::assertFalse($node->effect->storable);
    }

    public function testEvaluateDoesNotBorrowExpirationFromTheUnselectedBranch(): void
    {
        $node = AnalysisSource::node('return $flag ? $this->inputs->a() : Cached::of(1);');
        self::assertNotNull($node->metadataVariants);
        self::assertSame(TtlEstimateState::Invalid, $node->metadataVariants[1]->effect->ttl->state);
        self::assertNotEmpty($node->effect->problems);
        self::assertFalse($node->effect->ttl->hasFiniteExpiration());
    }
    public function testCallKeepsRepeatedUsesOfOneInvocationCorrelated(): void
    {
        $node = AnalysisSource::node('$v = $flag ? $this->inputs->a() : $this->inputs->b(); return $v->zip($v);');
        self::assertNotNull($node->metadataVariants);
        self::assertSame([['a'], ['b']], array_map(static fn (CacheVariant $v): array => $v->effect->tags, $node->metadataVariants));
    }

    public function testUniqueDeduplicatesEquivalentSwitchFallthroughPaths(): void
    {
        $node = AnalysisSource::node('switch ($flag) { case 0: case 1: return $this->inputs->a(); default: return $this->inputs->b(); }', direct: true);
        self::assertNotNull($node->metadataVariants);
        self::assertCount(2, $node->metadataVariants);
    }

    public function testProductKeepsIndependentSelectionsIndependent(): void
    {
        $node = AnalysisSource::node('$first = $flag ? $this->inputs->a() : $this->inputs->b(); $second = $flag ? $this->inputs->a() : $this->inputs->c(); return $first->zip($second);');
        self::assertNotNull($node->metadataVariants);
        self::assertCount(4, $node->metadataVariants);
        self::assertSame([['a'], ['a', 'c'], ['a', 'b'], ['b', 'c']], array_map(static fn (CacheVariant $v): array => $v->effect->tags, $node->metadataVariants));
    }

    public function testEvaluateBoundsExcessiveAlternativesWithoutSelectingACandidate(): void
    {
        $arms = implode(', ', array_map(static fn (int $index): string => $index.' => $this->inputs->a()', range(0, 128)));
        $node = AnalysisSource::node('return match ($flag) { '.$arms.' };');
        self::assertNotEmpty($node->analysisWarnings);
        self::assertNull($node->effect->ttl->seconds);
        self::assertTrue($node->effect->tagsUnknown);
    }

    public function testCallTreatsResolvedImplementationsAsAlternatives(): void
    {
        $children = AnalysisSource::node('return $flag ? $this->inputs->a() : $this->inputs->b();', direct: true)->children;
        $variants = (new FlowEffects())->call($children);
        self::assertCount(2, $variants);
        self::assertSame([['a'], ['b']], array_map(static fn (CacheVariant $v): array => $v->effect->tags, $variants));
    }

    public function testEvaluateKeepsARecursiveAlternativeUnknown(): void
    {
        $node = AnalysisSource::node('return $flag ? $this->inputs->a() : $this->pick($flag);');
        self::assertNotEmpty($node->analysisWarnings);
        self::assertFalse($node->effect->ttl->hasFiniteExpiration());
        self::assertNull($node->effect->ttl->seconds);
    }

    public function testCarriedMarksAValueAsHoldingMetadata(): void
    {
        $effects = new FlowEffects();
        $bare = new CacheVariant(new CacheEffect(TtlEstimate::known(20)));

        self::assertTrue($effects->carried([$bare], false)[0]->cached);
        self::assertFalse($effects->carried([$bare], true)[0]->analyzed);
        self::assertTrue($effects->carried([$effects->carried([$bare], false)[0]], true)[0]->cached);
    }

    public function testDetachedLeavesTheConstraintsOfItsCarrierBehind(): void
    {
        $effects = new FlowEffects();
        $carrier = $effects->carried([new CacheVariant(new CacheEffect(TtlEstimate::known(20)))], false)[0];

        $detached = $effects->detached([$carrier]);

        self::assertSame(TtlEstimateState::Unconstrained, $detached[0]->effect->ttl->state);
        self::assertFalse($effects->detached([new CacheVariant(new CacheEffect(TtlEstimate::known(20)))])[0]->analyzed);
    }
}
