<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Policy;

/**
 * Selects how a cached method obtains its expiration time.
 */
enum Ttl
{
    /**
     * Inherit the earliest expiration from the method's dependencies.
     */
    case Auto;

    /**
     * Inherit an upstream expiration and clamp it to CachePolicy::$maxTtl.
     */
    case FromUpstream;
}
