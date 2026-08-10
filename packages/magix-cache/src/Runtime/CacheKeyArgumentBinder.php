<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use function array_key_exists;
use function count;

use InvalidArgumentException;

use function is_int;

use Magix\Cache\Attribute\CacheIgnore;
use ReflectionMethod;

/**
 * Binds reflected method parameters to their normalized cache-key values.
 *
 * @internal
 */
final readonly class CacheKeyArgumentBinder
{
    /**
     * Creates an argument binder using the supplied cache-key reducer.
     */
    public function __construct(private CacheKeyReducer $reducer = new CacheKeyReducer())
    {
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed>
     */
    public function bind(ReflectionMethod $method, array $arguments): array
    {
        $keyArguments = [];
        $position = 0;

        foreach ($method->getParameters() as $parameter) {
            $ignored = $parameter->getAttributes(CacheIgnore::class) !== [];

            if ($parameter->isVariadic()) {
                foreach ($arguments as $key => $value) {
                    if (is_int($key) && $key < $position) {
                        continue;
                    }

                    if (!$ignored) {
                        $keyArguments[$parameter->getName().'['.$key.']'] = $this->reducer->reduce($parameter, $value);
                    }
                }

                return $keyArguments;
            }

            if (array_key_exists($position, $arguments)) {
                $value = $arguments[$position];
                ++$position;
            } elseif ($parameter->isDefaultValueAvailable()) {
                $value = $parameter->getDefaultValue();
            } else {
                throw new InvalidArgumentException('Cannot bind cache-key argument $'.$parameter->getName().'.');
            }

            if (!$ignored) {
                $keyArguments[$parameter->getName()] = $this->reducer->reduce($parameter, $value);
            }
        }

        if (count($arguments) > $position) {
            throw new InvalidArgumentException('Cache-key derivation received more arguments than the method declares.');
        }

        return $keyArguments;
    }
}
