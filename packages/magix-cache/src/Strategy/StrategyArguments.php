<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use InvalidArgumentException;

use function is_array;
use function is_scalar;

use UnitEnum;

/**
 * Copies immutable construction configuration and resolves nested definitions.
 */
final readonly class StrategyArguments
{
    /**
     * Copies configuration recursively, detaching array references.
     *
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException when configuration contains shared state or excessive nesting
     */
    public function copy(array $arguments, int $depth = 0): array
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Strategy configuration must be acyclic and at most 64 arrays deep.');
        }

        $copy = [];

        foreach ($arguments as $name => $value) {
            if (is_array($value)) {
                $copy[$name] = $this->copy($value, $depth + 1);
            } elseif ($value === null || is_scalar($value) || $value instanceof UnitEnum || $value instanceof StrategyDefinition) {
                $copy[$name] = $value;
            } else {
                throw new InvalidArgumentException('Strategy arguments accept values and construction definitions, never executable objects, closures or resources.');
            }
        }

        return $copy;
    }

    /**
     * Creates independent constructor arguments, including nested strategies.
     *
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    public function instantiate(array $arguments): array
    {
        $result = $this->copy($arguments);

        foreach ($result as $name => $value) {
            if (is_array($value)) {
                $result[$name] = $this->instantiate($value);
            } elseif ($value instanceof StrategyDefinition) {
                $result[$name] = $value->instantiate();
            }
        }

        return $result;
    }
}
