<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Adds the parameter's non-negative integer seconds as a lifetime constraint.
 *
 * The constraint is evaluated at the origin base time and met with all other
 * constraints, including a fixed policy TTL. The parameter remains keyed.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheTtl
{
}
