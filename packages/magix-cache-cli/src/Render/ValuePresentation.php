<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;

/**
 * Makes compact labels from detailed facts without changing their certainty.
 */
final readonly class ValuePresentation
{
    /**
     * Renders a proven bound before considering any unproven numeric reference.
     */
    public function ttl(CacheNode $node): string
    {
        if (!$node->boundary->isCacheBoundary && $node->boundary->policy !== null) {
            return $node->boundary->policy->ttlLabel().' [declared]';
        }

        $effect = $node->effect;
        $ttl = $effect->ttl;

        if ($ttl->state !== TtlEstimateState::Unknown) {
            return $ttl->label();
        }

        if ($ttl->alternatives !== null || $ttl->upperBound !== null || ($ttl->lowerBound !== null && $ttl->lowerBound > 0)) {
            return $ttl->label();
        }

        if ($effect->analysis->ttlReference !== null) {
            return $effect->analysis->ttlReference->seconds.'s?';
        }

        return $effect->analysis->ttl === [] && $ttl->hasFiniteExpiration() ? 'dynamic' : '?';
    }

    /**
     * A proven floor takes precedence over an unverified visibility reference.
     */
    public function visibility(CacheEffect $effect): string
    {
        if (!$effect->visibilityUnknown || $effect->visibility === Visibility::NoStore) {
            return strtolower($effect->visibility->name);
        }

        if ($effect->visibility === Visibility::Private) {
            return '≥private';
        }

        $reference = $effect->analysis->visibilityReference?->value();

        return $reference === null ? '?' : strtolower($reference->name).'?';
    }

    /**
     * Limits names while keeping guaranteed tags distinct from unverified references.
     */
    public function tags(CacheEffect $effect): string
    {
        $tags = array_slice($effect->tags, 0, 3);
        $reference = $effect->tagsUnknown ? $effect->analysis->tagsReference?->value() : null;
        $tentative = $reference === null ? [] : array_values(array_diff($reference, $effect->tags));
        $room = max(0, 3 - count($tags));

        if (count($effect->tags) > 3) {
            $tags[] = '+'.(count($effect->tags) - 3);
        }

        $tags = [...$tags, ...array_map(static fn (string $tag): string => $tag.'?', array_slice($tentative, 0, $room))];

        if (count($tentative) > $room) {
            $tags[] = '+'.(count($tentative) - $room).'?';
        }

        if ($effect->tagsUnknown && $tentative === []) {
            $tags[] = $reference === [] && $effect->tags === [] ? '[]?' : '?';
        }

        return $tags === [] ? '-' : implode(',', $tags);
    }
}
