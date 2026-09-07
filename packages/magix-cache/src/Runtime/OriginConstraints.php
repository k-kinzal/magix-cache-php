<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;
use LogicException;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Magix\Cache\Runtime\Policy\PolicySemantics;

/**
 * Applies the fixed success-stage constraint order at one base time.
 *
 * Parameter and dynamic-TTL constraints are met before the policy, all
 * evaluated at the same base time taken right after the origin succeeded.
 * Every step goes through the metadata meet, so no step can relax what a
 * dependency already imposed.
 *
 * @internal
 */
final readonly class OriginConstraints
{
    /**
     * Creates the success-stage constraint application.
     */
    public function __construct(private PolicySemantics $semantics = new PolicySemantics())
    {
    }

    /**
     * Returns the origin metadata with all declared constraints applied.
     *
     * @param Cached<mixed> $result
     * @param int<0, max>|null $parameterTtl Validated parameter constraint at the same base time.
     * @throws InvalidArgumentException when the resolver returns a negative lifetime
     * @throws LogicException when a derived lifetime has no finite upstream expiration to derive from
     */
    public function apply(
        CachePolicy $policy,
        ?CacheTtlResolver $resolver,
        Cached $result,
        string $key,
        float $baseTime,
        ?int $parameterTtl = null,
    ): CacheMetadata {
        $metadata = $result->metadata;

        if ($parameterTtl !== null) {
            $metadata = $metadata->meet(CacheMetadata::forTtl($parameterTtl, $baseTime));
        }

        if ($resolver !== null) {
            $ttl = $resolver->resolve(new DynamicTtlContext($key, $result, $baseTime));

            if ($ttl < 0) {
                throw new InvalidArgumentException('A dynamically resolved TTL must be zero or greater.');
            }

            $metadata = $metadata->meet(CacheMetadata::forTtl($ttl, $baseTime));
        }

        return $metadata->meet($this->semantics->constraint($policy, $metadata->expiresAt, $baseTime));
    }
}
