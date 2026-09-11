<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function array_merge;
use function array_unique;
use function array_values;
use function is_int;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

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
     * @param bool $composition Read what each child composes instead of what it returns, for the page-level estimate of a method that stores nothing.
     */
    public function constrain(array $children, bool $hasGaps = false, bool $composition = false): DependencyConstraint
    {
        $ttl = $hasGaps ? TtlEstimate::unknown(condition: 'cache propagation through uncached methods is unanalyzed') : TtlEstimate::unconstrained();
        $ttlSource = null;
        $visibility = Visibility::Shared;
        $visibilitySource = null;
        $tags = [];
        $expirations = [];
        $visibilityUnknown = $hasGaps;
        $tagsUnknown = $hasGaps;
        $analysis = new MetadataAnalysis();

        foreach ($children as $child) {
            $effect = $composition ? $child->composition() : $child->effect;
            $analysis = $analysis->merge($effect->analysis);
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

        return new DependencyConstraint($ttl, $ttlSource, $visibility, $visibilitySource, $this->tags($tags), $visibilityUnknown, $tagsUnknown, $children !== [], $expirations, $analysis);
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
            analysis: $constraint->analysis,
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
        $estimate = match (true) {
            $policy->ttl === null => TtlEstimate::unknown(condition: 'the declared ttl cannot be read statically'),
            is_int($policy->ttl) => $this->fixed($policy->ttl),
            default => $this->inherited($upstream, $constraint->ttlSource ?? 'a dependency'),
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
        return $declared < 0 ? TtlEstimate::invalid('A declared TTL must be zero or greater') : TtlEstimate::known($declared);
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
     * Returns the composed lifetime kept by a boundary that declares none.
     *
     * Ttl::Auto contributes no constraint of its own, so the estimate is
     * exactly what the dependencies bubbled up, including the certainty that
     * they supplied nothing. Only the derivation of a proven lifetime is
     * named, so a report can say where the number came from.
     */
    public function inherited(TtlEstimate $upstream, string $source): TtlEstimate
    {
        return $upstream->seconds === null ? $upstream : TtlEstimate::known($upstream->seconds, 'inherited from '.$source);
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
            analysis: $constraint->analysis,
        );
    }
}
