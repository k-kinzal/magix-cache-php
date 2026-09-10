<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\ParameterEffects;

/**
 * Applies strategy replacements to the provenance of individual metadata fields.
 */
final readonly class StrategyAnalysis
{
    /**
     * A readable writer replaces inherited uncertainty with a runtime choice.
     */
    public function apply(BoundaryDeclaration $boundary, CacheEffect $effect, MetadataAnalysis $analysis, DependencyConstraint $constraint): MetadataAnalysis
    {
        $strategy = $effect->strategy;

        if ($strategy === null) {
            return $analysis;
        }

        $fields = [];

        if ($strategy->overridesExpiration !== false) {
            $fields[] = 'ttl';
        }

        if ($strategy->writes->visibility) {
            $fields[] = 'visibility';
        }

        if ($strategy->writes->tags) {
            $fields[] = 'tags';
        }

        $before = $analysis;
        $analysis = $analysis->without(...$fields);

        if ($strategy->overridesExpiration === null && $strategy->problems === []) {
            $analysis = $analysis->withCause(AnalysisCause::at($boundary, 'unreadable-strategy', 'The strategy construction or its contracts could not be followed'), $fields);
            $references = $this->references($boundary, $constraint, $effect, $before);
            $analysis = $analysis->withMetadataReferences(
                $strategy->writes->visibility ? $references->visibilityReference : $analysis->visibilityReference,
                $strategy->writes->tags ? $references->tagsReference : $analysis->tagsReference,
            );
            $ttl = $boundary->policy?->ttl;

            if (is_int($ttl) && $ttl >= 0 && !$boundary->hasDynamicTtl && (new ParameterEffects())->ttl($boundary) === null) {
                $analysis = $analysis->withReference(new TtlReference($ttl, [$boundary->id()], 'declared policy before an unverified strategy'));
            }
        }

        return $analysis;
    }
    /**
     * Reads the same policy and parameter stages before an opaque strategy can replace them.
     */
    public function references(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect, MetadataAnalysis $analysis): MetadataAnalysis
    {
        $parameters = new ParameterEffects();
        $beforeStrategy = new CacheEffect(visibility: $effect->visibility, tags: $effect->tags);
        [$visibility, $visibilityUnknown] = $parameters->visibility($boundary, $constraint, $beforeStrategy);
        [$tags, $tagsUnknown] = $parameters->tags($boundary, $constraint, $beforeStrategy);
        $inputs = [new CacheVariant(new CacheEffect(
            visibility: $visibility,
            tags: $tags,
            visibilityUnknown: $visibilityUnknown,
            tagsUnknown: $tagsUnknown,
            analysis: $analysis,
        ), [$boundary->id()], cached: true)];

        return new MetadataAnalysis(
            visibilityReference: MetadataReference::fromVisibility($inputs),
            tagsReference: MetadataReference::fromTags($inputs),
        );
    }
}
