<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function implode;
use function is_int;
use function is_string;
use function strrpos;
use function substr;
use function var_export;

/**
 * Holds one #[UseStrategy] declaration exactly as written at a boundary.
 */
final readonly class UseStrategyDeclaration
{
    /**
     * Creates a statically read strategy usage.
     *
     * @param string $strategy Fully qualified class name whose create() builds the strategy.
     * @param array<array-key, mixed> $arguments Arguments for create(), by name or position; a value may be Unresolved::Value.
     */
    public function __construct(
        public string $strategy,
        public array $arguments = [],
        public int $line = 0,
    ) {
    }

    /**
     * Returns the declared construction rendered as a source-like summary.
     */
    public function label(): string
    {
        $separator = strrpos($this->strategy, '\\');
        $class = $separator === false ? $this->strategy : substr($this->strategy, $separator + 1);
        $rendered = [];

        foreach ($this->arguments as $name => $value) {
            $prefix = is_int($name) ? '' : $name.': ';

            if ($value === Unresolved::Value) {
                $rendered[] = $prefix.'?';

                continue;
            }

            $rendered[] = $prefix.(is_string($value) ? "'".$value."'" : var_export($value, true));
        }

        return $class.'::create('.implode(', ', $rendered).')';
    }
}
