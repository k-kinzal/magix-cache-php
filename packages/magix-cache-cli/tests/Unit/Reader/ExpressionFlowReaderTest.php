<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Reader;

use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Reader\ExpressionFlowReader;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\AnalysisSource;

#[CoversClass(ExpressionFlowReader::class)]
#[UsesNamespace('Magix\Cache')]
final class ExpressionFlowReaderTest extends TestCase
{
    public function testReadKeepsCoalescedReturnValuesAsAlternatives(): void
    {
        $node = AnalysisSource::node('return $this->inputs->a() ?? $this->inputs->b();');
        self::assertSame('20/60s', $node->effect->ttl->label());
    }

    public function testMethodMapPreservesOnlyTheReceiverMetadata(): void
    {
        $node = AnalysisSource::node('return $this->inputs->a()->map(fn () => $this->inputs->c()->value());');
        self::assertSame(20, $node->effect->ttl->seconds);
        self::assertSame(['a'], $node->effect->tags);
        self::assertSame([], $node->gaps);
    }

    public function testExtractionOfANestedWrapperExposesItsInnerCachedValue(): void
    {
        $node = AnalysisSource::node('return Cached::of($this->inputs->b())->value();');
        self::assertSame(60, $node->effect->ttl->seconds);
        self::assertSame(['b'], $node->effect->tags);
    }

    public function testStaticCallOfWithoutMetadataDoesNotBubbleItsValue(): void
    {
        $node = AnalysisSource::node('return Cached::of($this->inputs->c()->value());', '#[Cache(ttl: 120)]');
        self::assertSame(Visibility::Shared, $node->effect->visibility);
        self::assertSame([], $node->effect->tags);
        self::assertSame([], $node->gaps);
    }

    public function testCallbackParameterShadowsAnOuterCachedVariable(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->c(); return $this->inputs->a()->flatMap(fn ($v) => $v);', '#[Cache(ttl: 120)]');
        self::assertTrue($node->effect->visibilityUnknown);
        self::assertSame(['a'], $node->effect->tags);
        self::assertNotEmpty($node->effect->analysis->causes());
    }

    public function testMetadataPreservesAnExplicitlyRewrappedChild(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->b(); return Cached::of($v->value(), $v->metadata);');
        self::assertSame(60, $node->effect->ttl->seconds);
        self::assertSame(['b'], $node->effect->tags);
    }

    public function testTraverseEmptyInputDoesNotRunItsCallback(): void
    {
        $node = AnalysisSource::node('return Cached::traverse([], fn () => $this->inputs->c());', '#[Cache(ttl: 120)]');
        self::assertSame([], $node->effect->tags);
        self::assertSame(Visibility::Shared, $node->effect->visibility);
    }

    public function testNestedFlattenBubblesTheValueReturnedByMap(): void
    {
        $node = AnalysisSource::node('return $this->inputs->a()->map(fn () => $this->inputs->b())->flatten();');
        self::assertSame(20, $node->effect->ttl->seconds);
        self::assertSame(['a', 'b'], $node->effect->tags);
    }

    public function testProjectionOfUnzipKeepsBothSidesMetadata(): void
    {
        $node = AnalysisSource::node('return $this->inputs->a()->zip($this->inputs->b())->unzip()[0];');
        self::assertSame(['a', 'b'], $node->effect->tags);
    }

    public function testProjectionOfAnExtractedValueKeepsItDetachedWithoutAReturnType(): void
    {
        $node = AnalysisSource::node('return $this->inputs->a()->value()["id"];', '#[Cache(ttl: 120)]', origin: 'return Cached::of($this->bridge->pick($flag));');
        self::assertSame([], $node->effect->analysis->causes());
        self::assertSame([], $node->children[0]->effect->tags);
        self::assertSame('unconstrained', $node->children[0]->effect->ttl->label());
    }

    public function testCollectionSequenceMeetsEachPossibleCombination(): void
    {
        $node = AnalysisSource::node('return Cached::sequence([$flag ? $this->inputs->a() : $this->inputs->b(), $this->inputs->c()]);');
        self::assertNotNull($node->metadataVariants);
        self::assertSame([['a', 'c'], ['b', 'c']], array_map(static fn (CacheVariant $v): array => $v->effect->tags, $node->metadataVariants));
    }

    public function testArgumentsComposeEveryCombineInput(): void
    {
        $node = AnalysisSource::node('return $this->inputs->a()->combine3($this->inputs->b(), $this->inputs->c())->map(fn ($a, $b, $c) => $a);');
        self::assertSame(['a', 'b', 'c'], $node->effect->tags);
        self::assertSame(Visibility::NoStore, $node->effect->visibility);
    }

    public function testArgumentResolvesReorderedNamedMetadata(): void
    {
        $node = AnalysisSource::node('$v = $this->inputs->b(); return Cached::of(metadata: $v->metadata, value: $v->value());');
        self::assertSame(60, $node->effect->ttl->seconds);
        self::assertSame(['b'], $node->effect->tags);
    }
    public function testMethodDoesNotApplyCachedSemanticsToAnUnrelatedMapMethod(): void
    {
        $node = AnalysisSource::node('return (new Collection())->map(fn () => $this->inputs->a());', '#[Cache(ttl: 120)]');
        self::assertNotEmpty($node->effect->analysis->causes());
        self::assertTrue($node->effect->visibilityUnknown);
        self::assertTrue($node->effect->tagsUnknown);
    }

    public function testTypesResolveAReceiverAtThePositionTheCallIsWritten(): void
    {
        $node = AnalysisSource::node('$q = $this->inputs; $first = $q->a(); $q = $this->other; return $first;');

        self::assertSame('20s', $node->effect->ttl->label());
    }

    public function testContentsReadsTheValueACachedIsBuiltAround(): void
    {
        $node = AnalysisSource::node('$box = Cached::of($this->inputs->a()); return $box->value();');

        self::assertSame('20s', $node->effect->ttl->label());
        self::assertSame(['a'], $node->effect->tags);
    }
}
