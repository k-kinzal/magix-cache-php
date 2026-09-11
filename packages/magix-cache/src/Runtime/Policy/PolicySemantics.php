<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Policy;

use function is_int;

use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * Applies explicit policy fields without recomposing dependency metadata.
 *
 * A fixed TTL replaces expiration; Ttl::Auto declares none and keeps the
 * composed one. Unspecified tags and visibility are preserved.
 */
final readonly class PolicySemantics
{
    /**
     * Returns the metadata after applying the policy at the origin base time.
     */
    public function apply(CachePolicy $policy, CacheMetadata $metadata, float $baseTime): CacheMetadata
    {
        if (is_int($policy->ttl)) {
            $metadata = $metadata->withExpiration($baseTime + $policy->ttl);
        }

        if ($policy->tags !== null) {
            $metadata = $metadata->withTags($policy->tags);
        }

        return $policy->visibility === null ? $metadata : $metadata->withVisibility($policy->visibility);
    }
}
