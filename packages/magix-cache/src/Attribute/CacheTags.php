<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Adds the parameter's list of cache tags to the policy and dependency tags.
 *
 * Every tag must be a valid cache token. The parameter remains keyed.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheTags
{
}
