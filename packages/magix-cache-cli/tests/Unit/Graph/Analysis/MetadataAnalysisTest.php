<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\AnalysisCause;
use Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis;
use Magix\Cache\Cli\Graph\Analysis\TtlReference;
use Magix\Cache\Cli\Graph\CacheEffect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataAnalysis::class)]
#[UsesNamespace('Magix\Cache')]
final class MetadataAnalysisTest extends TestCase
{
    public function testWithCauseAttachesOnlyTheAffectedFields(): void
    {
        $cause = AnalysisCause::at(null, 'test', 'unreadable tags');
        $analysis = (new MetadataAnalysis())->withCause($cause, ['tags']);
        self::assertSame([], $analysis->ttl);
        self::assertSame([$cause->id => $cause], $analysis->tags);
    }

    public function testMergeDeduplicatesSharedCausesAndDoesNotReviveConflictingReferences(): void
    {
        $cause = AnalysisCause::at(null, 'test', 'unreadable return');
        $first = (new MetadataAnalysis())->withCause($cause)->withReference(new TtlReference(10, ['A'], 'input'));
        $other = (new MetadataAnalysis())->withCause($cause)->withReference(new TtlReference(20, ['B'], 'input'));
        $merged = $first->merge($other)->merge($first);
        self::assertCount(1, $merged->causes());
        self::assertNull($merged->ttlReference);
    }

    public function testWithoutStopsProvenanceAtTheFieldReplacement(): void
    {
        $cause = AnalysisCause::at(null, 'test', 'unreadable return');
        $source = (new MetadataAnalysis())->withCause($cause)->withReference(new TtlReference(10, ['A'], 'input'));
        $replaced = $source->without('ttl');
        self::assertSame([], $replaced->ttl);
        self::assertNull($replaced->ttlReference);
        self::assertSame($source->tags, $replaced->tags);
    }

    public function testWithReferenceNeverCreatesAProvenConstraint(): void
    {
        $effect = new CacheEffect(analysis: (new MetadataAnalysis())->withReference(new TtlReference(10, ['A'], 'input')));
        self::assertNull($effect->ttl->seconds);
        self::assertNull($effect->ttl->upperBound);
        self::assertFalse($effect->storable);
    }

    public function testCausesCollectsOneSharedCauseAcrossFields(): void
    {
        $cause = AnalysisCause::at(null, 'test', 'unreadable return');
        self::assertSame([$cause->id => $cause], (new MetadataAnalysis())->withCause($cause)->causes());
    }

    public function testEqualsComparesStableCauseAndReferenceValues(): void
    {
        $first = (new MetadataAnalysis())->withCause(AnalysisCause::at(null, 'test', 'unreadable return'));
        $second = (new MetadataAnalysis())->withCause(AnalysisCause::at(null, 'test', 'unreadable return'));
        self::assertTrue($first->equals($second));
        self::assertFalse($first->equals($second->without('ttl')));
    }

    public function testJsonSerializeKeepsReferencesSeparateFromCauseDefinitions(): void
    {
        $cause = AnalysisCause::at(null, 'test', 'unreadable return');
        $data = (new MetadataAnalysis())->withCause($cause)->jsonSerialize();
        self::assertSame([$cause->id], $data['ttl']);
        self::assertSame([$cause->id], $data['tags']);
        self::assertNull($data['ttlReference']);
    }

    public function testWithMetadataReferencesKeepsReferencesIndependentFromConstraints(): void
    {
        $visibility = new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([\Magix\Cache\Metadata\Visibility::NoStore], ['Child']);
        $tags = new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([['product']], ['Child']);
        $analysis = (new MetadataAnalysis())->withMetadataReferences($visibility, $tags)->withCause(AnalysisCause::at(null, 'opaque', 'unknown'));
        $effect = new CacheEffect(visibilityUnknown: true, tagsUnknown: true, analysis: $analysis);
        self::assertSame(\Magix\Cache\Metadata\Visibility::Shared, $effect->visibility);
        self::assertSame([], $effect->tags);
        self::assertSame($visibility, $analysis->withReference(new TtlReference(10, ['Child'], 'input'))->visibilityReference);
        self::assertSame($tags, $analysis->without('ttl')->tagsReference);
        self::assertNull($analysis->without('tags')->tagsReference);
        self::assertSame($visibility, $analysis->without('tags')->visibilityReference);
        self::assertNull($analysis->without('visibility')->visibilityReference);
        self::assertSame($tags, $analysis->jsonSerialize()['tagsReference']);
        self::assertFalse($analysis->equals($analysis->without('tags')));
    }

    public function testMergeRetainsMetadataReferenceConflictsAndAllSharedSources(): void
    {
        $a = new MetadataAnalysis(tagsReference: new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([['a']], ['A']));
        $b = new MetadataAnalysis(tagsReference: new \Magix\Cache\Cli\Graph\Analysis\MetadataReference([['b']], ['B']));
        $merged = $a->merge($b)->merge($a);
        self::assertNotNull($merged->tagsReference);
        self::assertNull($merged->tagsReference->value());
        self::assertSame(['A', 'B'], $merged->tagsReference->sources);
    }
}
