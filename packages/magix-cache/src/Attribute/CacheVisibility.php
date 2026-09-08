<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Restricts visibility with the parameter's Metadata\Visibility enum value.
 *
 * The value is met with the static policy and scopes before lookup, so
 * NoStore also prevents reads. The parameter remains keyed.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheVisibility
{
}
