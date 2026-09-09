<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Policy;

use LogicException;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * Applies explicit policy fields without recomposing dependency metadata.
 *
 * Auto inherits expiration. FromUpstream caps the inherited expiration.
 * A fixed TTL replaces it. Unspecified tags and visibility are preserved.
 * Finite-expiration validation runs after dynamic and strategy overrides.
 */
final readonly class PolicySemantics
{
    /**
     * Returns the metadata after applying the policy at the origin base time.
     *
     * @throws LogicException when FromUpstream has no maximum lifetime
     */
    public function apply(CachePolicy $policy, CacheMetadata $metadata, float $baseTime): CacheMetadata
    {
        if (is_int($policy->ttl)) {
            $metadata = $metadata->withExpiration($baseTime + $policy->ttl);
        } elseif ($policy->ttl === Ttl::FromUpstream) {
            $maximum = $policy->maxTtl ?? throw new LogicException('Ttl::FromUpstream requires maxTtl.');

            if ($metadata->expiresAt !== null) {
                $metadata = $metadata->withExpiration(min($metadata->expiresAt, $baseTime + $maximum));
            }
        }

        if ($policy->tags !== null) {
            $metadata = $metadata->withTags($policy->tags);
        }

        return $policy->visibility === null ? $metadata : $metadata->withVisibility($policy->visibility);
    }

    /**
     * Checks the final expiration after all explicit overrides have run.
     *
     * @throws LogicException when an automatic boundary has no finite expiration
     */
    public function validate(CachePolicy $policy, CacheMetadata $metadata): void
    {
        if ($policy->ttl instanceof Ttl && $metadata->expiresAt === null) {
            throw new LogicException('Ttl::'.$policy->ttl->name.' requires a finite expiration from the origin or an override.');
        }
    }
}
