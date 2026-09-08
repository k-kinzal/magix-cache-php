<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Identifies local settings that restrict metadata bubbling from composition.
 *
 * Equal constraints and unproven runtime choices are not restrictions.
 * Tags always union, so adding local tags never stops their propagation.
 */
final readonly class LocalRestrictions
{
    /**
     * Returns explanations for the fields a local setting provably restricts.
     *
     * @return array{ttl?: string, visibility?: string}
     */
    public function describe(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect, bool $visibilityUnknown): array
    {
        $restrictions = [];
        $ttl = $this->ttl($boundary, $constraint, $effect);

        if ($ttl !== null) {
            $restrictions['ttl'] = $ttl;
        }

        if ($constraint->hasDependencies && !$constraint->visibilityUnknown && !$visibilityUnknown
            && $effect->visibility !== $constraint->visibility) {
            $source = $effect->visibilityReason ?? 'local visibility';
            $restrictions['visibility'] = $source.'; composed '.strtolower($constraint->visibility->name);
        }

        return $restrictions;
    }

    /**
     * Names a fixed TTL or maxTtl cap that shortens the pre-policy lifetime.
     *
     * Finite alternatives can be capped on only some paths. Their complete
     * label is retained so a partial restriction never looks like a fixed TTL.
     */
    public function ttl(BoundaryDeclaration $boundary, DependencyConstraint $constraint, CacheEffect $effect): ?string
    {
        $policy = $boundary->policy;

        if ($policy === null || (!$constraint->hasDependencies && $effect->strategy === null)
            || $boundary->hasDynamicTtl || (new ParameterEffects())->ttl($boundary) !== null) {
            return null;
        }

        $cap = is_int($policy->ttl) ? $policy->ttl : ($policy->ttl === Ttl::FromUpstream ? $policy->maxTtl : null);

        if ($cap === null || $effect->ttl->state === TtlEstimateState::Invalid) {
            return null;
        }

        $composed = $effect->strategy === null ? $constraint->ttl : $constraint->ttl->meet($effect->strategy->ttl);

        if ((!$constraint->hasDependencies && $composed->state === TtlEstimateState::Unconstrained)
            || !$this->shortens($composed, $cap)) {
            return null;
        }

        $setting = is_int($policy->ttl) ? 'ttl' : 'maxTtl';

        return 'local '.$setting.' '.$cap.'s; composed '.$composed->label();
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
