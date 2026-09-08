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
 * statically decidable" stay separate all the way to the output. A declared
 * strategy contributes its candidate constraint the way the runtime meets
 * it: before the policy is applied, so a derived policy can take its
 * lifetime from what the strategy contracts guarantee.
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
        $visibilityUnknown = false;
        $tagsUnknown = false;

        foreach ($children as $child) {
            $effect = $child->effect;
            $visibilityUnknown = $visibilityUnknown || $effect->visibilityUnknown;
            $tagsUnknown = $tagsUnknown || $effect->tagsUnknown;
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

        return new DependencyConstraint($ttl, $ttlSource, $visibility, $visibilitySource, $this->tags($tags), $visibilityUnknown, $tagsUnknown, $children !== []);
    }

    /**
     * Returns a boundary's effective metadata or an uncached entry point's composed constraints.
     */
    public function calculate(BoundaryDeclaration $boundary, DependencyConstraint $constraint, ?StrategyEffect $strategy = null): CacheEffect
    {
        return $boundary->isCacheBoundary ? $this->applyPolicy($boundary, $constraint, $strategy) : $this->compose($constraint);
    }

    /**
     * Returns the metadata a cached() boundary produces once its policy is applied.
     */
    public function applyPolicy(BoundaryDeclaration $boundary, DependencyConstraint $constraint, ?StrategyEffect $strategy = null): CacheEffect
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
                problems: $this->problems([$problem], $strategy),
                strategy: $strategy,
            );
        }

        if ($policy->visibility->meet($visibility) !== $visibility) {
            $visibility = $policy->visibility->meet($visibility);
            $reason = 'declared by the policy';
        }

        $estimate = $this->lifetime($boundary, $policy, $constraint, $strategy);
        $problems = $this->problems(
            $estimate->state === TtlEstimateState::Invalid && $estimate->reason !== null ? [$estimate->reason] : [],
            $strategy,
        );

        $effect = new CacheEffect(
            ttl: $estimate,
            visibility: $visibility,
            storable: $this->storable($estimate, $visibility, $problems),
            tags: $this->tags(array_merge($constraint->tags, $policy->tags)),
            visibilityReason: $reason,
            problems: $problems,
            strategy: $strategy,
        );

        return (new ParameterEffects())->apply($boundary, $constraint, $effect);
    }

    /**
     * Returns an uncached entry point's composed constraints without inventing a policy or a stored entry.
     */
    public function compose(DependencyConstraint $constraint): CacheEffect
    {
        return new CacheEffect(
            ttl: $constraint->ttl,
            visibility: $constraint->visibility,
            tags: $constraint->tags,
            visibilityReason: $constraint->visibilitySource === null ? null : 'restricted by '.$constraint->visibilitySource,
            problems: $constraint->ttl->state === TtlEstimateState::Invalid && $constraint->ttl->reason !== null ? [$constraint->ttl->reason] : [],
            visibilityUnknown: $constraint->visibilityUnknown,
            tagsUnknown: $constraint->tagsUnknown,
        );
    }

    /**
     * Returns the lifetime estimate a policy resolves to for one boundary.
     *
     * The candidate constraint of a declared strategy is met into the
     * upstream constraint first, mirroring the runtime stage order.
     */
    public function lifetime(BoundaryDeclaration $boundary, PolicyDeclaration $policy, DependencyConstraint $constraint, ?StrategyEffect $strategy = null): TtlEstimate
    {
        $declared = $policy->ttl;
        $upstream = $constraint->ttl;
        $candidate = $strategy?->ttl;
        $combined = $candidate === null ? $upstream : $upstream->meet($candidate);
        $parameterTtl = (new ParameterEffects())->ttl($boundary);
        $combined = $parameterTtl === null ? $combined : $combined->meet($parameterTtl);
        $source = $candidate !== null && !$combined->equals($upstream)
            ? 'the declared strategy'
            : ($constraint->ttlSource ?? 'a dependency');

        if ($combined->state === TtlEstimateState::Invalid) {
            $estimate = $combined;
        } elseif ($declared === null) {
            $estimate = TtlEstimate::unknown(condition: 'the declared ttl cannot be read statically');
        } elseif (is_int($declared)) {
            $estimate = $this->fixed($declared, $combined, $source);
        } else {
            $estimate = $this->derived($declared, $policy->maxTtl, $boundary, $combined, $source, $strategy?->addsConstraint === true || $parameterTtl !== null);
        }

        if ($candidate !== null && $upstream->state === TtlEstimateState::Unknown && !$upstream->hasFiniteExpiration()
            && $estimate->state === TtlEstimateState::Unknown && $estimate->reason === null) {
            $estimate = $estimate->withCondition('an upstream expiration may shorten the lifetime');
        }

        if ($boundary->hasDynamicTtl) {
            $estimate = $estimate->meet(TtlEstimate::unknown(
                condition: 'a #[DynamicTtl] resolver decides the final lifetime at runtime',
            ));
        }

        return $parameterTtl === null ? $estimate : $parameterTtl->meet($estimate);
    }

    /**
     * Returns the estimate of a fixed lifetime bounded by its upstream.
     *
     * A fixed lifetime is always capped by the upstream expiration and never
     * fails: with no upstream constraint the declared value stands, and with
     * an unknown upstream the declared value remains as an upper bound while
     * a proven lower bound survives.
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

        $met = TtlEstimate::known($declared)->meet($upstream);

        if ($met->seconds !== null) {
            return $met->seconds === $declared
                ? TtlEstimate::known($declared)
                : TtlEstimate::known($met->seconds, 'declared '.$declared.'s, capped by '.$source);
        }

        if ($upstream->hasFiniteExpiration() && $met->reason === null) {
            return $met;
        }

        return $met->withCondition('an upstream expiration may shorten the declared '.$declared.'s');
    }

    /**
     * Returns the estimate of a lifetime derived from the upstream expiration.
     *
     * Ttl::Auto and Ttl::FromUpstream require a finite upstream expiration.
     * A strategy whose contracts definitely add a finite constraint fulfills
     * the requirement; otherwise a confirmed missing expiration is an error
     * unless the boundary itself supplies metadata or resolves a lifetime at
     * runtime, and an unknown one keeps the requirement as a runtime
     * condition.
     */
    public function derived(Ttl $declared, ?int $maxTtl, BoundaryDeclaration $boundary, TtlEstimate $upstream, string $source, bool $strategyAddsConstraint = false): TtlEstimate
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
            $capped = $cap === null ? $upstream : $upstream->meet(TtlEstimate::known($cap));

            if ($strategyAddsConstraint || $upstream->hasFiniteExpiration()) {
                return $capped;
            }

            return $capped->withCondition('requires a finite upstream expiration at runtime');
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
     * Reports whether the boundary provably stores its result.
     *
     * A range with a positive proven lower bound and no runtime condition
     * stores; a lifetime under a runtime condition cannot be proven to.
     *
     * @param list<string> $problems
     */
    public function storable(TtlEstimate $estimate, Visibility $visibility, array $problems): bool
    {
        $floor = $estimate->seconds
            ?? ($estimate->state === TtlEstimateState::Unknown && $estimate->reason === null ? $estimate->lowerBound : null);

        return $floor !== null && $floor > 0 && $visibility !== Visibility::NoStore && $problems === [];
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

    /**
     * Returns the effect problems including those of the declared strategy.
     *
     * @param list<string> $problems
     * @return list<string>
     */
    public function problems(array $problems, ?StrategyEffect $strategy): array
    {
        return array_values(array_unique([...$problems, ...($strategy->problems ?? [])]));
    }
}
