<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Parameter;

use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\Visibility;

/**
 * Holds validated configuration for exactly one invocation.
 *
 * @internal
 */
final readonly class ParameterConfiguration
{
    /**
     * @param int<0, max>|null $ttl Validated override lifetime in seconds.
     * @param list<non-empty-string>|null $tags Validated cache tokens.
     * @param array<string, mixed> $strategyArguments Values passed to create().
     */
    public function __construct(
        public ?int $ttl = null,
        public ?array $tags = null,
        public ?Visibility $visibility = null,
        public array $strategyArguments = [],
    ) {
    }

    /**
     * Overrides explicitly bound tags and visibility; omitted bindings inherit.
     */
    public function policy(CachePolicy $policy): CachePolicy
    {
        return new CachePolicy(
            ttl: $policy->ttl,
            maxTtl: $policy->maxTtl,
            tags: $this->tags ?? $policy->tags,
            visibility: $this->visibility ?? $policy->visibility,
            version: $policy->version,
        );
    }
}
