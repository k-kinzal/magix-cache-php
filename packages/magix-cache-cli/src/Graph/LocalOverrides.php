<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;

/**
 * Identifies explicit local fields that replace bubbled metadata.
 *
 * Equal values still have a local owner, so a field is reported by who wrote
 * it rather than by whether the value changed.
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

        $parameters = new ParameterEffects();
        $writes = $effect->strategy?->writes;

        if ($writes?->visibility !== true
            && ($boundary->policy?->visibility !== null || $boundary->scope() !== null || $parameters->sources($boundary, 'visibility') !== [])) {
            $overrides['visibility'] = ($effect->visibilityReason ?? 'local visibility').'; composed '.strtolower($constraint->visibility->name);
        }

        if ($writes?->tags !== true && ($boundary->policy?->tags !== null || $parameters->sources($boundary, 'tags') !== [])) {
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

        return null;
    }
}
