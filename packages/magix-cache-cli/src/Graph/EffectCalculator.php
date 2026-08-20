<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function array_merge;
use function array_unique;
use function array_values;
use function is_int;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

use function min;
use function sort;

/**
 * Applies the composition rules of MagixCache to a statically read boundary.
 */
final readonly class EffectCalculator
{
    /**
     * Returns the constraints the dependencies of a boundary impose on it.
     *
     * @param list<CacheNode> $children
     */
    public function constrain(array $children): DependencyConstraint
    {
        $ttl = null;
        $ttlSource = null;
        $visibility = Visibility::Shared;
        $visibilitySource = null;
        $tags = [];

        foreach ($children as $child) {
            $effect = $child->effect;
            $tags = array_merge($tags, $effect->tags);

            if ($effect->ttl !== null && ($ttl === null || $effect->ttl < $ttl)) {
                $ttl = $effect->ttl;
                $ttlSource = $child->boundary->shortId();
            }

            if ($effect->visibility->meet($visibility) !== $visibility) {
                $visibility = $effect->visibility->meet($visibility);
                $visibilitySource = $child->boundary->shortId();
            }
        }

        return new DependencyConstraint($ttl, $ttlSource, $visibility, $visibilitySource, $this->tags($tags));
    }

    /**
     * Returns the metadata a boundary produces once its policy is applied.
     */
    public function calculate(BoundaryDeclaration $boundary, DependencyConstraint $constraint): CacheEffect
    {
        $visibility = $constraint->visibility;
        $reason = $constraint->visibilitySource === null ? null : 'restricted by '.$constraint->visibilitySource;
        $scope = $boundary->scope();

        if ($scope->meet($visibility) !== $visibility) {
            $visibility = $scope->meet($visibility);
            $reason = 'restricted by a scoped parameter';
        }

        $policy = $boundary->policy;

        if ($policy === null) {
            return new CacheEffect(
                visibility: $visibility,
                tags: $constraint->tags,
                visibilityReason: $reason,
                problems: ['no #[Cache] attribute and no CachePolicy argument, so cached() throws a LogicException'],
            );
        }

        if ($policy->visibility->meet($visibility) !== $visibility) {
            $visibility = $policy->visibility->meet($visibility);
            $reason = 'declared by the policy';
        }

        $lifetime = $this->lifetime($boundary, $policy, $constraint);

        return new CacheEffect(
            ttl: $lifetime->ttl,
            visibility: $visibility,
            storable: $lifetime->ttl !== null
                && $lifetime->ttl > 0
                && $visibility !== Visibility::NoStore
                && $lifetime->problems === [],
            tags: $this->tags(array_merge($constraint->tags, $policy->tags)),
            ttlReason: $lifetime->ttlReason,
            visibilityReason: $reason,
            problems: $lifetime->problems,
        );
    }

    /**
     * Returns an effect that carries only the lifetime a policy resolves to.
     */
    public function lifetime(BoundaryDeclaration $boundary, PolicyDeclaration $policy, DependencyConstraint $constraint): CacheEffect
    {
        $declared = $policy->ttl;
        $inherited = $constraint->ttl;
        $source = $constraint->ttlSource ?? 'a dependency';

        if (is_int($declared)) {
            $clamped = $policy->clamp && $inherited !== null && $inherited < $declared;

            return new CacheEffect(
                ttl: $clamped ? $inherited : $declared,
                ttlReason: $clamped ? 'declared '.$declared.'s, clamped by '.$source : null,
            );
        }

        if ($declared === Ttl::FromUpstream) {
            $maximum = $policy->maxTtl;

            if ($maximum === null) {
                return new CacheEffect(problems: ['Ttl::FromUpstream requires maxTtl']);
            }

            return new CacheEffect(
                ttl: $inherited === null ? $maximum : min($inherited, $maximum),
                ttlReason: 'upstream expiration capped at '.$maximum.'s',
            );
        }

        if ($declared === null) {
            return new CacheEffect(problems: ['the policy is created at runtime and cannot be read from the source']);
        }

        if ($inherited !== null) {
            return new CacheEffect(ttl: $inherited, ttlReason: 'inherited from '.$source);
        }

        if ($boundary->hasStrategy || $boundary->suppliesMetadata) {
            return new CacheEffect(ttlReason: 'supplied at runtime by the boundary itself');
        }

        return new CacheEffect(problems: ['Ttl::Auto without a dependency or upstream expiration, so applying the policy throws a LogicException']);
    }

    /**
     * Returns cache tags deduplicated and sorted the way the runtime stores them.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public function tags(array $tags): array
    {
        $unique = array_values(array_unique($tags));
        sort($unique);

        return $unique;
    }
}
