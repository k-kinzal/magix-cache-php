<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Render;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Render\ValuePresentation;
use Magix\Cache\Metadata\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(ValuePresentation::class)]
#[UsesNamespace('Magix\Cache')]
final class ValuePresentationTest extends TestCase
{
    public function testTtlSeparatesReferencesBoundsAndRuntimeChoices(): void
    {
        $values = new ValuePresentation();
        $partial = ReportSource::node('Page::automatic');
        self::assertSame('10s?', $values->ttl($partial));
        self::assertSame('60s', $values->ttl(ReportSource::node('Page::fixed')));
        self::assertSame('?', $values->ttl(new CacheNode($partial->boundary, new CacheEffect())));
        self::assertSame('≤30s', $values->ttl(new CacheNode($partial->boundary, new CacheEffect(TtlEstimate::unknown(30), analysis: $partial->effect->analysis))));
        self::assertSame('dynamic', $values->ttl(new CacheNode($partial->boundary, new CacheEffect(TtlEstimate::unknown(lowerBound: 0, finite: true)))));
        self::assertSame('60s [declared]', $values->ttl(ReportSource::node('Migration::get')));
    }

    public function testVisibilityKeepsAProvenFloorWithoutInventingCertainty(): void
    {
        $values = new ValuePresentation();
        self::assertSame('?', $values->visibility(new CacheEffect(visibilityUnknown: true)));
        self::assertSame('≥private', $values->visibility(new CacheEffect(visibility: Visibility::Private, visibilityUnknown: true)));
        self::assertSame('private', $values->visibility(new CacheEffect(visibility: Visibility::Private)));
    }

    public function testTagsBoundsOverviewLengthWithoutChangingTheResult(): void
    {
        $effect = new CacheEffect(tags: ['a', 'b', 'c', 'd', 'e'], tagsUnknown: true);
        self::assertSame('a,b,c,+2,?', (new ValuePresentation())->tags($effect));
        self::assertCount(5, $effect->tags);
    }

    public function testVisibilityDistinguishesTentativeValuesFromProvenFloors(): void
    {
        $reference = new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([Visibility::Private], ['Child']);
        $analysis = new \Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis(visibilityReference: $reference);
        $values = new ValuePresentation();
        self::assertSame('shared?', $values->visibility(ReportSource::node('Page::automatic')->effect));
        self::assertSame('private?', $values->visibility(new CacheEffect(visibilityUnknown: true, analysis: $analysis)));
        self::assertSame('≥private', $values->visibility(new CacheEffect(visibility: Visibility::Private, visibilityUnknown: true, analysis: $analysis)));
        self::assertSame('shared', $values->visibility(new CacheEffect(analysis: $analysis)));
        self::assertSame('nostore', $values->visibility(new CacheEffect(visibility: Visibility::NoStore, visibilityUnknown: true, analysis: $analysis)));
    }

    public function testTagsSeparatesTentativeNamesFromGuaranteedNamesAndUnknownRemainders(): void
    {
        $reference = new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([['product']], ['Child']);
        $analysis = new \Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis(tagsReference: $reference);
        $values = new ValuePresentation();
        self::assertSame('product?', $values->tags(new CacheEffect(tagsUnknown: true, analysis: $analysis)));
        self::assertSame('product,?', $values->tags(new CacheEffect(tags: ['product'], tagsUnknown: true, analysis: $analysis)));
        self::assertSame('page,product?', $values->tags(new CacheEffect(tags: ['page'], tagsUnknown: true, analysis: $analysis)));
        self::assertSame('page', $values->tags(new CacheEffect(tags: ['page'], analysis: $analysis)));
        self::assertSame('[]?', $values->tags(new CacheEffect(tagsUnknown: true, analysis: new \Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis(
            tagsReference: new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([[]], ['Child']),
        ))));
    }

    public function testTagsLimitsNamesWithoutMakingOmittedReferencesLookGuaranteed(): void
    {
        $analysis = new \Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis(
            tagsReference: new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([['c', 'd', 'e']], ['Child']),
        );
        self::assertSame('a,b,c?,+2?', (new ValuePresentation())->tags(new CacheEffect(tags: ['a', 'b'], tagsUnknown: true, analysis: $analysis)));
    }
}
