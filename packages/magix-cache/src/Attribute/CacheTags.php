<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;

/**
 * Replaces the policy and inherited tags with this parameter's list.
 *
 * Every tag must be a valid cache token. The parameter remains keyed.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheTags
{
}
