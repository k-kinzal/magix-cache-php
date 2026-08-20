<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Describes where a boundary policy was declared.
 */
enum PolicySource
{
    /**
     * The policy comes from a #[Cache] attribute on the method.
     */
    case MethodAttribute;

    /**
     * The policy comes from a #[Cache] attribute on the declaring class.
     */
    case ClassAttribute;

    /**
     * The policy comes from a CachePolicy passed to cached().
     */
    case ExplicitPolicy;

    /**
     * The policy exists but could not be read without executing the code.
     */
    case Unresolved;
}
