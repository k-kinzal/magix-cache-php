<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Graph\Analysis\MetadataReference;

/**
 * Summarizes alternatives using only facts shared by every possible result.
 */
final readonly class AlternativeEffects
{
    /**
     * @param non-empty-list<CacheVariant> $variants
     */
    public function summarize(array $variants): CacheEffect
    {
        $first = $variants[0]->effect;
        $ttl = $first->ttl;
        $visibility = $first->visibility;
        $tags = $first->tags;
        $storable = $first->storable;
        $visibilityUnknown = $first->visibilityUnknown;
        $tagsUnknown = $first->tagsUnknown;
        $problems = $first->problems;
        $expirations = $first->expirationConstraints;
        $analysis = $first->analysis;

        foreach (array_slice($variants, 1) as $variant) {
            $effect = $variant->effect;
            $analysis = $analysis->merge($effect->analysis);
            $ttl = $this->ttl($ttl, $effect->ttl);
            $visibilityUnknown = $visibilityUnknown || $effect->visibilityUnknown || $visibility !== $effect->visibility;
            $visibility = $visibility->meet($effect->visibility) === $visibility ? $effect->visibility : $visibility;
            $tagsUnknown = $tagsUnknown || $effect->tagsUnknown || $tags !== $effect->tags;
            $tags = array_values(array_intersect($tags, $effect->tags));
            $storable = $storable && $effect->storable;
            $problems = array_values(array_unique([...$problems, ...$effect->problems]));
            $expirations = array_values(array_filter($expirations, static fn (ExpirationEstimate $expiration): bool => in_array($expiration, $effect->expirationConstraints, true)));
        }

        return new CacheEffect(
            $ttl,
            $visibility,
            $storable,
            $tags,
            problems: $problems,
            strategy: $first->strategy,
            visibilityUnknown: $visibilityUnknown,
            tagsUnknown: $tagsUnknown,
            localOverrides: $first->localOverrides,
            expirationConstraints: $expirations,
            analysis: $analysis->withMetadataReferences(
                $analysis->visibilityReference === null ? null : MetadataReference::fromVisibility($variants),
                $analysis->tagsReference === null ? null : MetadataReference::fromTags($variants),
            ),
        );
    }

    /**
     * A union keeps disjoint durations; an unconstrained branch removes finite proof.
     */
    public function ttl(TtlEstimate $first, TtlEstimate $second): TtlEstimate
    {
        if ($first->equals($second)) {
            return $first;
        }

        if ($first->state === TtlEstimateState::Invalid || $second->state === TtlEstimateState::Invalid) {
            return TtlEstimate::unknown(condition: 'a branch has an invalid cache declaration');
        }

        if ($first->state === TtlEstimateState::Unconstrained || $second->state === TtlEstimateState::Unconstrained) {
            return TtlEstimate::unknown(condition: 'a branch supplies no expiration');
        }

        return TtlEstimate::fromRanges(
            new TtlRangeSet(...[...$first->ranges()->intervals, ...$second->ranges()->intervals]),
            $first->condition($second),
            $first->hasFiniteExpiration() && $second->hasFiniteExpiration(),
        );
    }
}
