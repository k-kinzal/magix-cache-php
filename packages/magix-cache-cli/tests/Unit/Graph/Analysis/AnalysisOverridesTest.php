<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Unit\Graph\Analysis;

use Magix\Cache\Cli\Graph\Analysis\AnalysisOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Package\Cli\Fixture\ReportSource;

#[CoversClass(AnalysisOverrides::class)]
#[UsesNamespace('Magix\Cache')]
final class AnalysisOverridesTest extends TestCase
{
    public function testApplyRemovesOnlyTheFieldsThatWereReplaced(): void
    {
        $automatic = ReportSource::node('Page::automatic')->effect;
        $fixed = ReportSource::node('Page::fixed')->effect;
        $replaced = ReportSource::node('Page::replaced')->effect;
        self::assertSame(['ttl' => 'partial', 'visibility' => 'partial', 'tags' => 'partial'], $automatic->certainty());
        self::assertSame(['ttl' => 'known', 'visibility' => 'partial', 'tags' => 'partial'], $fixed->certainty());
        self::assertSame([], $fixed->analysis->ttl);
        self::assertSame(array_keys($automatic->analysis->tags), array_keys($fixed->analysis->tags));
        self::assertSame([], $replaced->analysis->causes());
        self::assertNull($fixed->analysis->ttlReference);
        self::assertSame(60, $fixed->ttl->seconds);
    }

    public function testApplyRemovesMetadataReferencesWhenTheCorrespondingPolicyFieldIsReplaced(): void
    {
        $source = 'return opaque($this->inputs->b());';
        $visibility = \Tests\Package\Cli\Fixture\AnalysisSource::node($source, '#[Cache(ttl: 60, visibility: Visibility::Shared)]')->effect;
        self::assertNull($visibility->analysis->visibilityReference);
        self::assertSame(['b'], $visibility->analysis->tagsReference?->value());
        self::assertFalse($visibility->visibilityUnknown);

        $tags = \Tests\Package\Cli\Fixture\AnalysisSource::node($source, '#[Cache(ttl: 60, tags: [])]')->effect;
        self::assertNull($tags->analysis->tagsReference);
        self::assertSame(\Magix\Cache\Metadata\Visibility::Private, $tags->analysis->visibilityReference?->value());
        self::assertFalse($tags->tagsUnknown);
    }

    public function testApplyDoesNotKeepStaticReferencesAfterRuntimeParameterOverrides(): void
    {
        $source = ReportSource::node('Page::automatic');
        self::assertNotNull($source->metadataVariants);
        $boundary = new \Magix\Cache\Cli\Declaration\BoundaryDeclaration(
            'Page',
            'runtime',
            'page.php',
            1,
            new \Magix\Cache\Cli\Declaration\PolicyDeclaration(\Magix\Cache\Cli\Declaration\PolicySource::MethodAttribute, 60),
            parameters: [
                new \Magix\Cache\Cli\Declaration\KeyParameter('tags', 'array', configuration: new \Magix\Cache\Cli\Declaration\ParameterConfiguration(tags: true)),
                new \Magix\Cache\Cli\Declaration\KeyParameter('visibility', \Magix\Cache\Metadata\Visibility::class, configuration: new \Magix\Cache\Cli\Declaration\ParameterConfiguration(visibility: true)),
            ],
        );
        $effect = (new \Magix\Cache\Cli\Graph\EffectCalculator())->calculate($boundary, $source->metadataVariants[0]->constraint());
        self::assertNull($effect->analysis->visibilityReference);
        self::assertNull($effect->analysis->tagsReference);
        self::assertSame(['ttl' => 'known', 'visibility' => 'runtime', 'tags' => 'runtime'], $effect->certainty());
    }
}
