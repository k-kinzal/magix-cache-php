<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Magix\Cache\Cached;

/**
 * Exposes one successful origin result to a dynamic-TTL resolver.
 */
final readonly class DynamicTtlContext
{
    /**
     * @param Cached<covariant mixed> $result
     */
    public function __construct(
        public string $key,
        public Cached $result,
        public float $now,
    ) {
    }
}
