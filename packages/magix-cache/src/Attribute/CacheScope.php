<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use Magix\Cache\Runtime\Metadata\Visibility;

/**
 * Restricts cache visibility when the annotated parameter is present.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CacheScope
{
    /**
     * Creates a parameter visibility constraint.
     */
    public function __construct(public Visibility $visibility = Visibility::Private)
    {
    }
}
