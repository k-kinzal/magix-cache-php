<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

/**
 * Describes one normalized method invocation for a cache-key strategy.
 */
final readonly class CacheKeyContext
{
    /**
     * Creates a cache-key context.
     *
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $arguments,
        public string $version,
    ) {
    }
}
