<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Overrides expiration with the parameter's non-negative integer seconds.
 *
 * The lifetime is evaluated at the origin base time and replaces the policy
 * and inherited expiration. The parameter remains keyed.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheTtl
{
}
