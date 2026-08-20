<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Runtime\Metadata\Visibility;

/**
 * Holds the cache metadata a boundary produces once its policy is applied.
 */
final readonly class CacheEffect
{
    /**
     * Creates an effective cache result.
     *
     * @param int|null $ttl Effective lifetime in seconds, or null when no finite expiration is known.
     * @param list<string> $tags
     * @param list<string> $problems Reasons the boundary cannot work as written.
     */
    public function __construct(
        public ?int $ttl = null,
        public Visibility $visibility = Visibility::Shared,
        public bool $storable = false,
        public array $tags = [],
        public ?string $ttlReason = null,
        public ?string $visibilityReason = null,
        public array $problems = [],
    ) {
    }
}
