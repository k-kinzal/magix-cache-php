<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Policy;

use function is_int;

use LogicException;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * Computes the additional constraint a resolved policy imposes at one instant.
 *
 * The result is pure: it depends only on the policy, the upstream expiration,
 * and the evaluation time. The runtime obtains the final metadata by meeting
 * the origin metadata with this constraint, so a declared lifetime can never
 * extend an expiration a dependency already imposed.
 */
final readonly class PolicySemantics
{
    /**
     * Returns the constraint to meet with the origin metadata.
     *
     * @param float|null $upstreamExpiresAt The expiration already carried by the origin metadata.
     * @param float $now The base time taken right after the origin succeeded.
     * @throws LogicException when a derived lifetime has no finite upstream expiration to derive from
     */
    public function constraint(CachePolicy $policy, ?float $upstreamExpiresAt, float $now): CacheMetadata
    {
        if (is_int($policy->ttl)) {
            return new CacheMetadata(
                expiresAt: $now + $policy->ttl,
                tags: $policy->tags,
                visibility: $policy->visibility,
            );
        }

        if ($upstreamExpiresAt === null) {
            throw new LogicException('Ttl::'.$policy->ttl->name.' requires a finite dependency or upstream expiration.');
        }

        if ($policy->ttl === Ttl::FromUpstream) {
            $maxTtl = $policy->maxTtl ?? throw new LogicException('Ttl::FromUpstream requires maxTtl.');

            return new CacheMetadata(
                expiresAt: $now + $maxTtl,
                tags: $policy->tags,
                visibility: $policy->visibility,
            );
        }

        return new CacheMetadata(
            tags: $policy->tags,
            visibility: $policy->visibility,
        );
    }
}
