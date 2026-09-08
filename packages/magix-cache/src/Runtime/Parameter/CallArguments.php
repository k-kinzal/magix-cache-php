<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Parameter;

use InvalidArgumentException;
use LogicException;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Binds captured positional or named arguments before key reduction.
 *
 * @internal
 */
final readonly class CallArguments
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed>
     * @throws InvalidArgumentException when arguments cannot be bound uniquely
     */
    public function bind(ReflectionMethod $method, array $arguments): array
    {
        $values = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $position = $parameter->getPosition();

            if ($parameter->isVariadic()) {
                $values[$name] = $arguments;

                return $values;
            }

            if (array_key_exists($position, $arguments) && array_key_exists($name, $arguments)) {
                throw new InvalidArgumentException('Multiple values supplied for $'.$name.'.');
            }

            if (array_key_exists($position, $arguments)) {
                $values[$name] = $arguments[$position];
                unset($arguments[$position]);
            } elseif (array_key_exists($name, $arguments)) {
                $values[$name] = $arguments[$name];
                unset($arguments[$name]);
            } else {
                $values[$name] = $this->defaultValue($parameter);
            }
        }

        if ($arguments !== []) {
            throw new InvalidArgumentException('Arguments do not match the boundary parameters.');
        }

        return $values;
    }

    /**
     * @throws InvalidArgumentException when a required argument is missing
     * @throws LogicException when the declared default cannot be read
     */
    public function defaultValue(ReflectionParameter $parameter): mixed
    {
        if (!$parameter->isDefaultValueAvailable()) {
            throw new InvalidArgumentException('Cannot bind argument $'.$parameter->getName().'.');
        }

        try {
            return $parameter->getDefaultValue();
        } catch (ReflectionException $unavailable) {
            throw new LogicException('The default of $'.$parameter->getName().' cannot be read.', previous: $unavailable);
        }
    }
}
