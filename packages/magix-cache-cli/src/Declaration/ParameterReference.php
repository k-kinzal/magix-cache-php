<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * An unknown invocation value whose boundary parameter source is known.
 */
final readonly class ParameterReference
{
    /**
     * @param string $name Boundary parameter supplying this value.
     */
    public function __construct(public string $name)
    {
    }
}
