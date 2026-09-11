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
 * Ttl::Auto declares no lifetime and keeps the composed one.
 */
final readonly class CachePolicy
{
    /**
     * Creates an explicit cache policy.
     *
     * @param int|Ttl $ttl Fixed lifetime in seconds, or Ttl::Auto to keep the composed one.
     * @param list<string>|null $tags Replacement tags; null inherits, [] clears.
     * @throws InvalidArgumentException when a lifetime is negative, the version is empty, or a tag is unusable
     */
    public function __construct(
        public int|Ttl $ttl = Ttl::Auto,
        public ?array $tags = null,
        public ?Visibility $visibility = null,
        public string $version = '1',
    ) {
        if (is_int($ttl) && $ttl < 0) {
            throw new InvalidArgumentException('Cache TTL must be zero or greater.');
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
            tags: $this->tags,
            visibility: $visibility,
            version: $this->version,
        );
    }
}
