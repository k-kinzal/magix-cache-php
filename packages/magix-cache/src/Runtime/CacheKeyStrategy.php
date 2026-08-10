<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

/**
 * Converts a normalized method invocation into a cache key.
 */
interface CacheKeyStrategy
{
    /**
     * Returns a key accepted by the configured cache implementation.
     */
    public function generate(CacheKeyContext $context): string;
}
