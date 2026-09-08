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
     * @param int<0, max>|null $ttl Validated additional lifetime in seconds.
     * @param list<non-empty-string> $tags Validated cache tokens.
     * @param array<string, mixed> $strategyArguments Values passed to create().
     */
    public function __construct(
        public ?int $ttl = null,
        public array $tags = [],
        public Visibility $visibility = Visibility::Shared,
        public array $strategyArguments = [],
    ) {
    }

    /**
     * Returns the policy restricted by parameter visibility and tags.
     */
    public function policy(CachePolicy $policy): CachePolicy
    {
        return new CachePolicy(
            ttl: $policy->ttl,
            maxTtl: $policy->maxTtl,
            tags: [...$policy->tags, ...$this->tags],
            visibility: $policy->visibility->meet($this->visibility),
            version: $policy->version,
        );
    }
}
