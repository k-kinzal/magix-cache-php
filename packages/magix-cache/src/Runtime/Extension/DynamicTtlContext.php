<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

use Magix\Cache\Cached;

/**
 * Exposes one successful origin result to a dynamic-TTL resolver.
 */
final readonly class DynamicTtlContext
{
    /**
     * @param Cached<covariant mixed> $result
     * @param float $now The same base time the fixed policy is evaluated at.
     */
    public function __construct(
        public string $key,
        public Cached $result,
        public float $now,
    ) {
    }
}
