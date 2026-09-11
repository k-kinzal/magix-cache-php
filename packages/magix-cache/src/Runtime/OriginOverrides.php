<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Magix\Cache\Runtime\Policy\PolicySemantics;

/**
 * Applies policy, parameter TTL and dynamic TTL in ascending priority.
 *
 * All relative expirations use the same time taken after origin success.
 * Strategies receive this result and may override its metadata afterward.
 *
 * @internal
 */
final readonly class OriginOverrides
{
    /**
     * Creates the success-stage override application.
     */
    public function __construct(private PolicySemantics $semantics = new PolicySemantics())
    {
    }

    /**
     * Returns metadata with explicit boundary settings applied.
     *
     * @param Cached<mixed> $result
     * @param int<0, max>|null $parameterTtl Validated parameter override at the same base time.
     * @throws InvalidArgumentException when the resolver returns a negative lifetime
     */
    public function apply(
        CachePolicy $policy,
        ?CacheTtlResolver $resolver,
        Cached $result,
        string $key,
        float $baseTime,
        ?int $parameterTtl = null,
    ): CacheMetadata {
        $metadata = $this->semantics->apply($policy, $result->metadata, $baseTime);

        if ($parameterTtl !== null) {
            $metadata = $metadata->withExpiration($baseTime + $parameterTtl);
        }

        if ($resolver !== null) {
            $ttl = $resolver->resolve(new DynamicTtlContext($key, $result, $baseTime));

            if ($ttl < 0) {
                throw new InvalidArgumentException('A dynamically resolved TTL must be zero or greater.');
            }

            $metadata = $metadata->withExpiration($baseTime + $ttl);
        }

        return $metadata;
    }
}
