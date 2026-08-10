<?php

declare(strict_types=1);

namespace Magix\Cache;

use InvalidArgumentException;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Declares how a cache boundary constrains and stores its result.
 */
final readonly class CachePolicy
{
    /**
     * Creates an explicit cache policy.
     *
     * @param list<string> $tags
     */
    public function __construct(
        public int|Ttl $ttl = Ttl::Auto,
        public ?int $maxTtl = null,
        public array $tags = [],
        public Visibility $visibility = Visibility::Shared,
        public bool $clamp = true,
        public string $version = '1',
    ) {
        if (is_int($ttl) && $ttl < 0) {
            throw new InvalidArgumentException('Cache TTL must be zero or greater.');
        }

        if ($maxTtl !== null && $maxTtl < 0) {
            throw new InvalidArgumentException('Maximum cache TTL must be zero or greater.');
        }

        if ($ttl === Ttl::FromUpstream && $maxTtl === null) {
            throw new InvalidArgumentException('Ttl::FromUpstream requires maxTtl.');
        }

        if ($version === '') {
            throw new InvalidArgumentException('Cache version must not be empty.');
        }
    }

    /**
     * Returns this policy with an additional visibility constraint.
     */
    public function restrictVisibility(Visibility $visibility): self
    {
        $visibility = $this->visibility->meet($visibility);

        if ($visibility === $this->visibility) {
            return $this;
        }

        return new self(
            ttl: $this->ttl,
            maxTtl: $this->maxTtl,
            tags: $this->tags,
            visibility: $visibility,
            clamp: $this->clamp,
            version: $this->version,
        );
    }
}
