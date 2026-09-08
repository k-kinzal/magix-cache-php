<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Parameter;

use InvalidArgumentException;
use LogicException;
use Magix\Cache\Attribute\UseStrategy;
use ReflectionException;
use ReflectionMethod;

/**
 * Checks factory destinations once, before any invocation is evaluated.
 *
 * @internal
 */
final readonly class StrategyBindings
{
    /**
     * @param array<string, string> $bindings Factory destination to boundary parameter.
     * @throws InvalidArgumentException when a destination is absent, repeated or not a value parameter
     */
    public function validate(?UseStrategy $use, array $bindings): void
    {
        if ($bindings === []) {
            return;
        }

        if ($use === null) {
            throw new InvalidArgumentException('StrategyArgument requires an enabled UseStrategy declaration.');
        }

        $method = $this->factory($use);
        $parameters = [];
        $occupied = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $parameters[$name] = $parameter;

            if (array_key_exists($name, $use->arguments) || array_key_exists($parameter->getPosition(), $use->arguments)) {
                $occupied[$name] = true;
            }
        }

        foreach ($bindings as $target => $source) {
            $parameter = $parameters[$target] ?? null;

            if ($parameter === null || $parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new InvalidArgumentException('StrategyArgument on $'.$source.' requires a declared non-variadic value parameter $'.$target.' on create().');
            }

            if (isset($occupied[$target])) {
                throw new InvalidArgumentException('UseStrategy already supplies $'.$target.', also bound from $'.$source.'.');
            }
        }
    }

    /**
     * @throws LogicException when the strategy has no public static create()
     */
    public function factory(UseStrategy $use): ReflectionMethod
    {
        try {
            $method = new ReflectionMethod($use->strategy, 'create');
        } catch (ReflectionException $missing) {
            throw new LogicException($use->strategy.' declares no create().', previous: $missing);
        }

        if (!$method->isPublic() || !$method->isStatic()) {
            throw new LogicException($use->strategy.' must declare a public static create().');
        }

        return $method;
    }
}
