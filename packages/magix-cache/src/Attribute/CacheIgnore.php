<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Excludes a method parameter from cache key derivation.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheIgnore
{
}
