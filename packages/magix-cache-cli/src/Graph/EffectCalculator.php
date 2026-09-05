<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function array_merge;
use function array_unique;
use function array_values;
use function is_int;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

use function min;
use function sort;

/**
 * Applies the composition rules of MagixCache to a statically read boundary.
 *
 * The calculator works on TtlEstimate values, so "no constraint" and "not
 * statically decidable" stay separate all the way to the output.
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
        $ttl = TtlEstimate::unconstrained();
        $ttlSource = null;
        $visibility = Visibility::Shared;
        $visibilitySource = null;
        $tags = [];

        foreach ($children as $child) {
            $effect = $child->effect;
            $tags = array_merge($tags, $effect->tags);
            $met = $ttl->meet($effect->ttl);

            if (!$met->equals($ttl)) {
                $ttlSource = $child->boundary->shortId();
            }

            $ttl = $met;

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
            $problem = 'no #[Cache] attribute on the method or its concrete class, so cached() throws a LogicException';

            return new CacheEffect(
                ttl: TtlEstimate::invalid($problem),
                visibility: $visibility,
                tags: $constraint->tags,
                visibilityReason: $reason,
                problems: [$problem],
            );
        }

        if ($policy->visibility->meet($visibility) !== $visibility) {
            $visibility = $policy->visibility->meet($visibility);
            $reason = 'declared by the policy';
        }

        $estimate = $this->lifetime($boundary, $policy, $constraint);
        $problems = $estimate->state === TtlEstimateState::Invalid && $estimate->reason !== null
            ? [$estimate->reason]
            : [];

        return new CacheEffect(
            ttl: $estimate,
            visibility: $visibility,
            storable: $estimate->state === TtlEstimateState::Known
                && $estimate->seconds !== null
                && $estimate->seconds > 0
                && $visibility !== Visibility::NoStore
                && $problems === [],
            tags: $this->tags(array_merge($constraint->tags, $policy->tags)),
            visibilityReason: $reason,
            problems: $problems,
        );
    }

    /**
     * Returns the lifetime estimate a policy resolves to for one boundary.
     */
    public function lifetime(BoundaryDeclaration $boundary, PolicyDeclaration $policy, DependencyConstraint $constraint): TtlEstimate
    {
        $declared = $policy->ttl;
        $upstream = $constraint->ttl;

        if ($upstream->state === TtlEstimateState::Invalid) {
            $estimate = $upstream;
        } elseif ($declared === null) {
            $estimate = TtlEstimate::unknown(condition: 'the declared ttl cannot be read statically');
        } elseif (is_int($declared)) {
            $estimate = $this->fixed($declared, $upstream, $constraint->ttlSource ?? 'a dependency');
        } else {
            $estimate = $this->derived($declared, $policy->maxTtl, $boundary, $upstream, $constraint->ttlSource ?? 'a dependency');
        }

        if ($boundary->hasDynamicTtl) {
            return $estimate->meet(TtlEstimate::unknown(
                condition: 'a #[DynamicTtl] resolver decides the final lifetime at runtime',
            ));
        }

        return $estimate;
    }

    /**
     * Returns the estimate of a fixed lifetime bounded by its upstream.
     *
     * A fixed lifetime is always capped by the upstream expiration and never
     * fails: with no upstream constraint the declared value stands, and with
     * an unknown upstream only the declared value remains as an upper bound.
     */
    public function fixed(int $declared, TtlEstimate $upstream, string $source): TtlEstimate
    {
        if ($upstream->seconds !== null) {
            if ($upstream->seconds < $declared) {
                return TtlEstimate::known($upstream->seconds, 'declared '.$declared.'s, capped by '.$source);
            }

            return TtlEstimate::known($declared);
        }

        if ($upstream->state === TtlEstimateState::Unconstrained) {
            return TtlEstimate::known($declared);
        }

        return TtlEstimate::unknown(
            upperBound: min($upstream->upperBound ?? $declared, $declared),
            condition: 'an upstream expiration may shorten the declared '.$declared.'s',
        );
    }

    /**
     * Returns the estimate of a lifetime derived from the upstream expiration.
     *
     * Ttl::Auto and Ttl::FromUpstream require a finite upstream expiration:
     * a confirmed missing one is an error unless the boundary itself supplies
     * metadata or resolves a lifetime at runtime, and an unknown one keeps
     * the requirement as a runtime condition.
     */
    public function derived(Ttl $declared, ?int $maxTtl, BoundaryDeclaration $boundary, TtlEstimate $upstream, string $source): TtlEstimate
    {
        if ($declared === Ttl::FromUpstream && $maxTtl === null) {
            return TtlEstimate::invalid('Ttl::FromUpstream requires maxTtl, so the declaration cannot be constructed');
        }

        $cap = $declared === Ttl::FromUpstream ? $maxTtl : null;

        if ($upstream->seconds !== null) {
            return $cap === null
                ? TtlEstimate::known($upstream->seconds, 'inherited from '.$source)
                : TtlEstimate::known(min($upstream->seconds, $cap), 'upstream expiration capped at '.$cap.'s');
        }

        if ($upstream->state === TtlEstimateState::Unknown) {
            return TtlEstimate::unknown(
                upperBound: $cap === null ? $upstream->upperBound : min($upstream->upperBound ?? $cap, $cap),
                condition: 'requires a finite upstream expiration at runtime',
            );
        }

        if ($boundary->suppliesMetadata || $boundary->hasDynamicTtl) {
            return TtlEstimate::unknown(
                upperBound: $cap,
                condition: 'requires the boundary to supply a finite expiration at runtime',
            );
        }

        return TtlEstimate::invalid(
            'Ttl::'.$declared->name.' has no dependency with a finite expiration, so applying the policy throws a LogicException',
        );
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
