<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis;
use Magix\Cache\Cli\Graph\Analysis\MetadataReference;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataReference::class)]
#[UsesNamespace('Magix\Cache')]
final class MetadataReferenceTest extends TestCase
{
    public function testFromVisibilityUsesCarriedMetadataAndIgnoresPlainValues(): void
    {
        $private = new CacheVariant(new CacheEffect(visibility: Visibility::Private), ['PrivateQuery::get'], cached: true);
        $plain = new CacheVariant(new CacheEffect());
        $reference = MetadataReference::fromVisibility([$private, $plain]);
        self::assertNotNull($reference);
        self::assertSame(Visibility::Private, $reference->value());
        self::assertSame(['PrivateQuery::get'], $reference->sources);
        self::assertNull(MetadataReference::fromVisibility([$plain]));
        self::assertNull(MetadataReference::fromVisibility([new CacheVariant(new CacheEffect(visibilityUnknown: true), cached: true)]));
    }

    public function testFromTagsComparesNormalizedSetsAndRetainsEverySource(): void
    {
        $a = new CacheVariant(new CacheEffect(tags: ['product', 'common']), ['A'], cached: true);
        $b = new CacheVariant(new CacheEffect(tags: ['common', 'product', 'common']), ['B'], cached: true);
        $reference = MetadataReference::fromTags([$a, $b, new CacheVariant(new CacheEffect())]);
        self::assertNotNull($reference);
        self::assertSame(['common', 'product'], $reference->value());
        self::assertSame(['A', 'B'], $reference->sources);
        self::assertNull(MetadataReference::fromTags([new CacheVariant(new CacheEffect())]));
    }

    public function testFromTagsCarriesGuaranteedNamesAlongsideEarlierTentativeNames(): void
    {
        $previous = new MetadataReference([['product']], ['Product']);
        $effect = new CacheEffect(tags: ['page'], tagsUnknown: true, analysis: new MetadataAnalysis(tagsReference: $previous));
        $reference = MetadataReference::fromTags([new CacheVariant($effect, ['Page'], cached: true)]);
        self::assertNotNull($reference);
        self::assertSame(['page', 'product'], $reference->value());
        self::assertSame(['Page', 'Product'], $reference->sources);
    }

    public function testFromTagsDistinguishesAnObservedEmptySetFromNoReference(): void
    {
        $empty = MetadataReference::fromTags([new CacheVariant(new CacheEffect(), cached: true)]);
        self::assertNotNull($empty);
        self::assertSame([], $empty->value());
        self::assertNull(MetadataReference::fromTags([new CacheVariant(new CacheEffect(tagsUnknown: true), cached: true)]));
    }

    public function testValueRejectsAChoiceBetweenConflictingMetadata(): void
    {
        self::assertNull((new MetadataReference([Visibility::Shared, Visibility::Private], ['A', 'B']))->value());
        self::assertNull((new MetadataReference([['a'], ['b']], ['A', 'B']))->value());
    }

    public function testMergeKeepsConflictsThroughLaterObservations(): void
    {
        $a = new MetadataReference([['a']], ['A']);
        $b = new MetadataReference([['b']], ['B']);
        $merged = $a->merge($b)->merge($a);
        self::assertNull($merged->value());
        self::assertSame([['a'], ['b']], $merged->candidates);
        self::assertSame(['A', 'B'], $merged->sources);
    }

    public function testJsonSerializeKeepsReadableValuesCandidatesAndProvenance(): void
    {
        $reference = new MetadataReference([Visibility::Private], ['Child::get']);
        self::assertSame([
            'value' => 'private',
            'candidates' => ['private'],
            'sources' => ['Child::get'],
            'basis' => 'input metadata before an unverified operation',
        ], $reference->jsonSerialize());
        self::assertSame(['a'], (new MetadataReference([['a']], ['A']))->jsonSerialize()['value']);
    }
}
