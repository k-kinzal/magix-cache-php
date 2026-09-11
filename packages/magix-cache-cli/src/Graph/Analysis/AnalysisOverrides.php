<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\DependencyConstraint;
use Magix\Cache\Cli\Graph\ParameterEffects;
use Magix\Cache\Cli\Graph\TtlEstimateState;

/**
 * Applies the runtime's field replacement order to analysis provenance.
 */
final readonly class AnalysisOverrides
{
    /**
     * Keeps a cause only while the corresponding result still depends on it.
     */
    public function apply(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect): MetadataAnalysis
    {
        $analysis = $constraint->analysis;
        $policy = $boundary->policy;
        $parameters = new ParameterEffects();

        if (is_int($policy?->ttl) || $policy?->ttl === null) {
            $analysis = $analysis->without('ttl');
        }

        foreach (['ttl' => $policy?->ttl === null, 'visibility' => $policy->visibilityUnknown ?? false, 'tags' => $policy->tagsUnknown ?? false] as $field => $unknown) {
            if ($unknown) {
                $analysis = $analysis->without($field)->withCause(AnalysisCause::at($boundary, 'unreadable-policy', 'The declared '.$field.' could not be read'), [$field]);
            }
        }

        if ($boundary->hasDynamicTtl || $parameters->ttl($boundary) !== null) {
            $analysis = $analysis->without('ttl');
        }

        if ($policy?->visibility !== null || $boundary->scope() !== null || $parameters->sources($boundary, 'visibility') !== []) {
            $analysis = $analysis->without('visibility');
        }

        if ($policy?->tags !== null || $parameters->sources($boundary, 'tags') !== []) {
            $analysis = $analysis->without('tags');
        }

        $analysis = (new StrategyAnalysis())->apply($boundary, $effect, $analysis, $constraint);

        return $effect->ttl->state === TtlEstimateState::Known ? $analysis->without('ttl') : $analysis;
    }

}
