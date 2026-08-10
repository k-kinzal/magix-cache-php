<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Provides attribute syntax for an otherwise explicit cache policy.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Cache
{
    /**
     * Creates an attribute-backed cache policy.
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
        $this->policy();
    }

    /**
     * Converts this helper declaration to the shared explicit policy type.
     */
    public function policy(): CachePolicy
    {
        return new CachePolicy(
            ttl: $this->ttl,
            maxTtl: $this->maxTtl,
            tags: $this->tags,
            visibility: $this->visibility,
            clamp: $this->clamp,
            version: $this->version,
        );
    }
}
