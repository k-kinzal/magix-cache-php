<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Identifies explicit local fields that replace bubbled metadata.
 *
 * Equal values still have a local owner. FromUpstream is the explicit cap
 * operation and is highlighted only when it changes a proven lifetime.
 */
final readonly class LocalOverrides
{
    /**
     * @return array{ttl?: string, visibility?: string, tags?: string}
     */
    public function describe(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect): array
    {
        if (!$constraint->hasDependencies || $effect->ttl->state === TtlEstimateState::Invalid) {
            return [];
        }

        $overrides = [];
        $ttl = $this->ttl($boundary, $constraint, $effect);

        if ($ttl !== null) {
            $overrides['ttl'] = $ttl;
        }

        if ($effect->strategy?->metadataUnknown === true) {
            return $overrides;
        }

        $parameters = new ParameterEffects();

        if ($boundary->policy?->visibility !== null || $boundary->scope() !== null || $parameters->sources($boundary, 'visibility') !== []) {
            $overrides['visibility'] = ($effect->visibilityReason ?? 'local visibility').'; composed '.strtolower($constraint->visibility->name);
        }

        if ($boundary->policy?->tags !== null || $parameters->sources($boundary, 'tags') !== []) {
            $overrides['tags'] = 'local tags replace inherited tags';
        }

        return $overrides;
    }

    /**
     * Identifies the highest-priority local expiration writer.
     */
    public function ttl(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect): ?string
    {
        $strategy = $effect->strategy;

        if ($strategy !== null && $strategy->overridesExpiration !== false) {
            return $strategy->overridesExpiration === true ? 'overridden by the declared strategy' : null;
        }

        if ($boundary->hasDynamicTtl) {
            return 'overridden by #[DynamicTtl]';
        }

        if ((new ParameterEffects())->ttl($boundary) !== null) {
            return 'overridden by #[CacheTtl]';
        }

        $policy = $boundary->policy;

        if (is_int($policy?->ttl)) {
            return 'local ttl '.$policy->ttl.'s; composed '.$constraint->ttl->label();
        }

        if ($policy?->ttl === Ttl::FromUpstream && $policy->maxTtl !== null && $this->shortens($constraint->ttl, $policy->maxTtl)) {
            return 'local maxTtl '.$policy->maxTtl.'s; composed '.$constraint->ttl->label();
        }

        return null;
    }

    /**
     * Reports whether a local cap restricts any of the proven composed lifetimes.
     *
     * This numeric comparison is independent of the policy declaration and
     * never treats an unknown upper bound as an unlimited lifetime.
     */
    public function shortens(TtlEstimate $composed, int $cap): bool
    {
        if ($composed->state === TtlEstimateState::Invalid
            || ($composed->state === TtlEstimateState::Unknown && (!$composed->hasFiniteExpiration() || $composed->reason !== null))) {
            return false;
        }

        $ceiling = $composed->seconds ?? $composed->upperBound ?? $composed->lowerBound;

        return $composed->state === TtlEstimateState::Unconstrained || ($ceiling !== null && $ceiling > $cap);
    }
}
