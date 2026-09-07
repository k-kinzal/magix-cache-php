<?php

declare(strict_types=1);

namespace Magix\Cache\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Passes the annotated boundary argument to the active strategy's create().
 *
 * The destination must be a declared, non-variadic value parameter. Each
 * destination has one source, either UseStrategy or a boundary parameter.
 * A bound factory runs for every invocation, including fresh hits.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class StrategyArgument
{
    /**
     * @param string $name Destination parameter name on create(), without $.
     * @throws InvalidArgumentException when the destination name is empty
     */
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new InvalidArgumentException('StrategyArgument must name a create() parameter.');
        }
    }
}
