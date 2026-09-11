<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Policy;

/**
 * Marks a cached method as declaring no lifetime of its own.
 */
enum Ttl
{
    /**
     * Keep the composed expiration exactly as the dependencies bubbled it.
     */
    case Auto;
}
