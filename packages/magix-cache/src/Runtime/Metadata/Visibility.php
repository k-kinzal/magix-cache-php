<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Metadata;

/**
 * Describes where a value may be stored.
 */
enum Visibility: int
{
    /**
     * The value may be stored by shared caches such as a CDN.
     */
    case Shared = 0;

    /**
     * The value may be stored by the server when its key is personalized.
     */
    case Private = 1;

    /**
     * The value must not be stored anywhere.
     */
    case NoStore = 2;

    /**
     * Returns the more restrictive of two visibility constraints.
     */
    public function meet(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }
}
