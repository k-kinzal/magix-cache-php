<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheVariant;
use Magix\Cache\Cli\Graph\ExpirationEstimate;

/**
 * Displays correlated branch results without implying that every branch runs together.
 */
final readonly class AlternativePresentation
{
    /**
     * Returns a disjunction only when multiple return paths remain possible.
     */
    public function label(CacheNode $node): ?string
    {
        $variants = $node->metadataVariants ?? [];

        return count($variants) < 2 ? null : implode(' or ', array_map($this->variant(...), $variants));
    }

    /**
     * Keeps TTL, visibility and tags together within each possible result.
     */
    public function variant(CacheVariant $variant): string
    {
        $effect = $variant->effect;
        $source = $variant->sources === [] ? 'no cache dependency' : implode(' + ', $variant->sources);
        $expiration = $effect->expirationConstraints === [] ? '' : ', expires by '.ExpirationEstimate::describe($effect->expirationConstraints);

        return $source.' [ttl '.$effect->ttl->label().', '.$effect->visibilityLabel().', tags '.$effect->tagsLabel().$expiration.']';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(CacheVariant $variant): array
    {
        $effect = $variant->effect;

        return [
            'sources' => $variant->sources,
            'analyzed' => $variant->analyzed,
            'ttl' => $effect->ttl->jsonSerialize(),
            'visibility' => strtolower($effect->visibility->name),
            'visibilityUnknown' => $effect->visibilityUnknown,
            'tags' => $effect->tags,
            'tagsUnknown' => $effect->tagsUnknown,
            'expirationConstraints' => $effect->expirationConstraints,
            'storable' => $effect->storable,
            'problems' => $effect->problems,
        ];
    }
}
