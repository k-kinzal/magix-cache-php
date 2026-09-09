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
 * statically decidable" stay separate all the way to the output. Dependency
 * composition uses meet. Explicit local settings replace the selected fields
 * in the same priority order as runtime, with strategies applied last.
 */
final readonly class EffectCalculator
{
    /**
     * Returns the constraints the dependencies of a boundary impose on it.
     *
     * flatten, zip, sequence and traverse use this same meet of all inputs.
     * unzip preserves the whole pair's constraints on either projection, so
     * selecting one side must not remove a discovered dependency here.
     * An empty collection contributes the unconstrained identity.
     *
     * @param list<CacheNode> $children
     * @param bool $hasGaps An ordinary path reaches a cache child, but its metadata propagation has not been analyzed.
     */
    public function constrain(array $children, bool $hasGaps = false): DependencyConstraint
    {
        $ttl = $hasGaps ? TtlEstimate::unknown(condition: 'cache propagation through uncached methods is unanalyzed') : TtlEstimate::unconstrained();
        $ttlSource = null;
        $visibility = Visibility::Shared;
        $visibilitySource = null;
        $tags = [];
        $expirations = [];
        $visibilityUnknown = $hasGaps;
        $tagsUnknown = $hasGaps;

        foreach ($children as $child) {
            $effect = $child->effect;
            $expirations = [...$expirations, ...$effect->expirationConstraints];
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

        return new DependencyConstraint($ttl, $ttlSource, $visibility, $visibilitySource, $this->tags($tags), $visibilityUnknown, $tagsUnknown, $children !== [], $expirations);
    }

    /**
     * Returns a boundary's effective metadata or an uncached entry point's composed constraints.
     */
    public function calculate(BoundaryDeclaration $boundary, DependencyConstraint $constraint, ?StrategyEffect $strategy = null): CacheEffect
    {
        return $boundary->isCacheBoundary ? $this->applyPolicy($boundary, $constraint, $strategy) : $this->compose($constraint);
    }

    /**
     * Applies local overrides separately so fields from different branches never mix.
     *
     * @param list<CacheVariant> $variants
     * @return list<CacheVariant>
     */
    public function applyAlternatives(BoundaryDeclaration $boundary, array $variants, ?StrategyEffect $strategy): array
    {
        return (new FlowEffects())->unique(array_map(fn (CacheVariant $variant): CacheVariant => new CacheVariant(
            $this->calculate($boundary, $variant->constraint(), $strategy),
            $variant->sources,
            $variant->analyzed,
            cached: $boundary->isCacheBoundary || $variant->cached,
        ), $variants));
    }

    /**
     * Returns the metadata a cached() boundary produces once its policy is applied.
     */
    public function applyPolicy(BoundaryDeclaration $boundary, DependencyConstraint $constraint, ?StrategyEffect $strategy = null): CacheEffect
    {
        $policy = $boundary->policy;
        $visibility = $constraint->visibility;
        $reason = $constraint->visibilitySource === null ? null : 'inherited from '.$constraint->visibilitySource;

        if ($policy === null) {
            return $this->missingPolicy($constraint, $strategy, $visibility, $reason);
        }

        if ($policy->visibility !== null) {
            $visibility = $policy->visibility;
            $reason = 'declared by the policy';
        }

        $scope = $boundary->scope();

        if ($scope !== null) {
            $visibility = $scope;
            $reason = 'declared by a scoped parameter';
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
            tags: $this->tags($policy->tags ?? $constraint->tags),
            visibilityReason: $reason,
            problems: $problems,
            strategy: $strategy,
            expirationConstraints: $this->expirations($boundary, $constraint, $strategy),
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
            expirationConstraints: $constraint->expirationConstraints,
        );
    }

    /**
     * Applies policy, parameter TTL, dynamic TTL and strategy overrides.
     *
     * An invalid dependency remains a problem: overriding its metadata cannot
     * repair a boundary that fails before returning a value.
     */
    public function lifetime(BoundaryDeclaration $boundary, PolicyDeclaration $policy, DependencyConstraint $constraint, ?StrategyEffect $strategy = null): TtlEstimate
    {
        $upstream = $constraint->ttl;

        if ($upstream->state === TtlEstimateState::Invalid) {
            return $upstream;
        }

        $parameterTtl = (new ParameterEffects())->ttl($boundary);
        $willOverride = $parameterTtl !== null || $boundary->hasDynamicTtl || ($strategy !== null && $strategy->overridesExpiration !== false);
        $estimate = match (true) {
            $policy->ttl === null => TtlEstimate::unknown(condition: 'the declared ttl cannot be read statically'),
            is_int($policy->ttl) => $this->fixed($policy->ttl),
            default => $this->derived($policy->ttl, $policy->maxTtl, $boundary, $upstream, $constraint->ttlSource ?? 'a dependency', $willOverride),
        };

        if ($estimate->state === TtlEstimateState::Invalid) {
            return $estimate;
        }

        $estimate = $parameterTtl ?? $estimate;

        if ($boundary->hasDynamicTtl) {
            $estimate = TtlEstimate::unknown(condition: 'a #[DynamicTtl] resolver decides the lifetime at runtime', lowerBound: 0, finite: true);
        }

        return $strategy !== null && $strategy->overridesExpiration !== false ? $strategy->ttl : $estimate;
    }

    /**
     * A fixed policy replaces the inherited lifetime, including unknown bounds.
     */
    public function fixed(int $declared): TtlEstimate
    {
        return TtlEstimate::known($declared);
    }

    /**
     * Keeps daily deadlines only while their expiration survives overrides.
     *
     * @return list<ExpirationEstimate>
     */
    public function expirations(BoundaryDeclaration $boundary, DependencyConstraint $constraint, ?StrategyEffect $strategy): array
    {
        if ($strategy !== null && $strategy->overridesExpiration !== false) {
            return $strategy->expirations;
        }

        if ($boundary->hasDynamicTtl || (new ParameterEffects())->ttl($boundary) !== null || !$boundary->policy?->ttl instanceof Ttl) {
            return [];
        }

        return $constraint->expirationConstraints;
    }

    /**
     * Returns the estimate of a lifetime derived from the upstream expiration.
     *
     * Ttl::Auto and Ttl::FromUpstream require a finite upstream expiration.
     * A later expiration override can fulfill
     * the requirement; otherwise a confirmed missing expiration is an error
     * unless the boundary itself supplies metadata or resolves a lifetime at
     * runtime, and an unknown one keeps the requirement as a runtime
     * condition.
     */
    public function derived(Ttl $declared, ?int $maxTtl, BoundaryDeclaration $boundary, TtlEstimate $upstream, string $source, bool $willOverride = false): TtlEstimate
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

            if ($willOverride || $upstream->hasFiniteExpiration()) {
                return $capped;
            }

            return $capped->withCondition('requires a finite upstream expiration at runtime');
        }

        if ($willOverride) {
            return TtlEstimate::unconstrained();
        }

        if ($boundary->suppliesMetadata) {
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

    /**
     * Reports a missing policy while retaining all discovered constraints.
     */
    public function missingPolicy(DependencyConstraint $constraint, ?StrategyEffect $strategy, Visibility $visibility, ?string $reason): CacheEffect
    {
        $problem = 'no #[Cache] attribute on the method or its concrete class, so cached() throws a LogicException';

        return new CacheEffect(
            ttl: TtlEstimate::invalid($problem),
            visibility: $visibility,
            tags: $constraint->tags,
            visibilityReason: $reason,
            problems: $this->problems([$problem], $strategy),
            strategy: $strategy,
            expirationConstraints: [...$constraint->expirationConstraints, ...($strategy->expirations ?? [])],
        );
    }
}
