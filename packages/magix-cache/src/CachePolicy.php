<?php

declare(strict_types=1);

namespace Magix\Cache;

use InvalidArgumentException;

use function is_int;

use Magix\Cache\Metadata\CacheTokenSet;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Declares explicit overrides for a cache boundary.
 *
 * Omitted fields inherit bubbled metadata. A fixed TTL replaces expiration;
 * Auto inherits it and FromUpstream explicitly caps the inherited deadline.
 */
final readonly class CachePolicy
{
    /**
     * Creates an explicit cache policy.
     *
     * @param int|Ttl $ttl Fixed lifetime in seconds, or a lifetime derived from upstream.
     * @param int|null $maxTtl Upper bound applied to a derived lifetime.
     * @param list<string>|null $tags Replacement tags; null inherits, [] clears.
     * @throws InvalidArgumentException when a lifetime is negative, a derived lifetime has no upper bound, the version is empty, or a tag is unusable
     */
    public function __construct(
        public int|Ttl $ttl = Ttl::Auto,
        public ?int $maxTtl = null,
        public ?array $tags = null,
        public ?Visibility $visibility = null,
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

        if ($tags !== null) {
            (new CacheTokenSet())->tags($tags);
        }
    }

    /**
     * Returns this policy with an explicit visibility override.
     */
    public function withVisibility(Visibility $visibility): self
    {
        if ($visibility === $this->visibility) {
            return $this;
        }

        return new self(
            ttl: $this->ttl,
            maxTtl: $this->maxTtl,
            tags: $this->tags,
            visibility: $visibility,
            version: $this->version,
        );
    }
}
