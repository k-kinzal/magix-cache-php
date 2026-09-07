<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Runtime\Parameter\CallArguments;
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
        $values = (new CallArguments())->bind($method, $arguments);
        $keyArguments = [];

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getAttributes(CacheIgnore::class) !== []) {
                continue;
            }

            $value = $values[$parameter->getName()];

            if ($parameter->isVariadic() && is_array($value)) {
                foreach ($value as $key => $item) {
                    $keyArguments[$parameter->getName().'['.$key.']'] = $this->reducer->reduce($parameter, $item);
                }
            } else {
                $keyArguments[$parameter->getName()] = $this->reducer->reduce($parameter, $value);
            }
        }

        return $keyArguments;
    }
}
