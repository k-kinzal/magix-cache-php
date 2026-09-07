<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one declared parameter of a strategy constructor or create().
 */
final readonly class StrategyParameter
{
    /**
     * Creates a statically read parameter declaration.
     *
     * @param mixed $default Declared default value; Unresolved::Value when it cannot be read.
     */
    public function __construct(
        public string $name,
        public int $position,
        public bool $hasDefault = false,
        public mixed $default = null,
    ) {
    }
}
