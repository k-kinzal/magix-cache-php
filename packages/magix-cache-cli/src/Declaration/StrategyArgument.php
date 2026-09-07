<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one written argument of a strategy construction.
 *
 * The value passed to a constructor and the value a constructor may store
 * after normalizing are different things; an argument only records what was
 * written at the call site. A plain variable records its name so the binder
 * can follow it to a create() parameter.
 */
final readonly class StrategyArgument
{
    /**
     * Creates a statically read construction argument.
     *
     * @param string|null $name Parameter name when the argument was written named.
     * @param mixed $value Literal value; Unresolved::Value when it cannot be read.
     * @param string|null $variable Variable name when the argument is a plain variable.
     */
    public function __construct(
        public ?string $name,
        public mixed $value,
        public ?string $variable = null,
    ) {
    }
}
