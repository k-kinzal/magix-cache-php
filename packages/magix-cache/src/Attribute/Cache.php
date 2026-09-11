<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;
use Magix\Cache\CachePolicy;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Declares the cache policy and runtime reference of a boundary.
 *
 * A method-level declaration takes precedence over the concrete class
 * declaration as a whole; the two are never mixed per option.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Cache
{
    /**
     * Creates an attribute-backed cache policy.
     *
     * @param int|Ttl $ttl Fixed lifetime in seconds, or Ttl::Auto to keep the composed one.
     * @param list<string>|null $tags Replacement tags; null inherits, [] clears.
     * @param string $runtime Name of a runtime registered at bootstrap.
     * @throws InvalidArgumentException when a lifetime is negative, the version is empty, a tag is unusable, or the runtime reference is empty
     */
    public function __construct(
        public int|Ttl $ttl = Ttl::Auto,
        public ?array $tags = null,
        public ?Visibility $visibility = null,
        public string $version = '1',
        public string $runtime = CacheRuntimeRegistry::DEFAULT_NAME,
    ) {
        if ($runtime === '') {
            throw new InvalidArgumentException('Cache runtime reference must not be empty.');
        }

        $this->policy();
    }

    /**
     * Converts this helper declaration to the shared explicit policy type.
     */
    public function policy(): CachePolicy
    {
        return new CachePolicy(
            ttl: $this->ttl,
            tags: $this->tags,
            visibility: $this->visibility,
            version: $this->version,
        );
    }
}
