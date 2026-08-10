<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Reduces a parameter to a stable cache-key representation.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheKey
{
    /**
     * Creates a cache-key reducer declaration.
     *
     * @param array{class-string, non-empty-string} $reduce A public static callable.
     */
    public function __construct(public array $reduce)
    {
    }
}
