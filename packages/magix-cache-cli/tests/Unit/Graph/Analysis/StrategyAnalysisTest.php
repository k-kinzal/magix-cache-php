<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\StrategyAnalysis;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\TtlEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(StrategyAnalysis::class)]
#[UsesNamespace('Magix\Cache')]
final class StrategyAnalysisTest extends TestCase
{
    public function testApplySeparatesAnOpaqueOverrideFromAReadableRuntimeChoice(): void
    {
        $node = ReportSource::node('Page::fixed');
        $opaque = new CacheEffect(TtlEstimate::unknown(condition: 'strategy'), strategy: new StrategyEffect('External', TtlEstimate::unknown(condition: 'unreadable')));
        $partial = (new StrategyAnalysis())->apply($node->boundary, $opaque, $node->effect->analysis, new DependencyConstraint());
        self::assertSame(60, $partial->ttlReference?->seconds);
        self::assertSame('unreadable-strategy', array_values($partial->ttl)[0]->kind);
        self::assertSame($node->effect->analysis->tags, $partial->tags);

        $readable = new CacheEffect(TtlEstimate::unknown(condition: 'parameter', finite: true), strategy: new StrategyEffect('Dynamic', TtlEstimate::unknown(condition: 'parameter', finite: true), overridesExpiration: true));
        $runtime = (new StrategyAnalysis())->apply($node->boundary, $readable, $partial, new DependencyConstraint());
        self::assertSame([], $runtime->ttl);
        self::assertNull($runtime->ttlReference);
        self::assertSame($partial->tags, $runtime->tags);
    }

    public function testReferencesRetainsTheMetadataBeforeStrategyReplacement(): void
    {
        $node = ReportSource::node('Page::clean');
        $references = (new StrategyAnalysis())->references($node->boundary, new DependencyConstraint(tags: ['leaf']), $node->effect, $node->effect->analysis);
        self::assertSame(\Magix\Cache\Metadata\Visibility::Shared, $references->visibilityReference?->value());
        self::assertSame(['leaf'], $references->tagsReference?->value());
    }

    public function testApplyShowsPolicyReferencesOnlyUntilAKnownWriterReplacesThem(): void
    {
        $boundary = new \Magix\Cache\Cli\Declaration\BoundaryDeclaration(
            'Page',
            'get',
            'page.php',
            1,
            new \Magix\Cache\Cli\Declaration\PolicyDeclaration(
                \Magix\Cache\Cli\Declaration\PolicySource::MethodAttribute,
                60,
                tags: ['page'],
                visibility: \Magix\Cache\Metadata\Visibility::Private
            ),
        );
        $writes = new \Magix\Cache\Cli\Declaration\MetadataContract(visibility: true, tags: true);
        $opaque = new StrategyEffect('Opaque', TtlEstimate::unknown(), writes: $writes);
        $effect = (new \Magix\Cache\Cli\Graph\EffectCalculator())->calculate($boundary, new DependencyConstraint(), $opaque);
        self::assertSame(\Magix\Cache\Metadata\Visibility::Private, $effect->analysis->visibilityReference?->value());
        self::assertSame(['page'], $effect->analysis->tagsReference?->value());
        self::assertSame(\Magix\Cache\Metadata\Visibility::Shared, $effect->visibility);
        self::assertSame([], $effect->tags);
        self::assertFalse($effect->storable);

        $known = new StrategyEffect('Readable', TtlEstimate::unconstrained(), overridesExpiration: false, writes: $writes);
        $runtime = (new \Magix\Cache\Cli\Graph\EffectCalculator())->calculate($boundary, new DependencyConstraint(), $known);
        self::assertNull($runtime->analysis->visibilityReference);
        self::assertNull($runtime->analysis->tagsReference);
        self::assertSame(['ttl' => 'known', 'visibility' => 'runtime', 'tags' => 'runtime'], $runtime->certainty());
    }
}
