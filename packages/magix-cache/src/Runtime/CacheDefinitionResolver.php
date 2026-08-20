<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;
use LogicException;
use Magix\Cache\Attribute\Cache;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Resolves and memoizes reflected cache declarations.
 *
 * @internal
 */
final class CacheDefinitionResolver
{
    /**
     * Resolved cache definitions indexed by class and method.
     *
     * @var array<string, CacheDefinition>
     */
    private array $definitions = [];

    /**
     * Returns the resolved method and effective method-or-class cache policy.
     *
     * @throws LogicException when the boundary that called cached() cannot be reflected
     * @throws InvalidArgumentException when a parameter is both scoped and ignored
     */
    public function resolve(object $service, string $methodName): CacheDefinition
    {
        $key = $service::class.'::'.$methodName;

        if (isset($this->definitions[$key])) {
            return $this->definitions[$key];
        }

        try {
            $method = new ReflectionMethod($service, $methodName);
        } catch (ReflectionException $missing) {
            throw new LogicException($service::class.'::'.$methodName.' is not a method that can be reflected.', previous: $missing);
        }

        $attributes = $method->getAttributes(Cache::class);

        if ($attributes === []) {
            $attributes = (new ReflectionClass($service))->getAttributes(Cache::class);
        }

        return $this->definitions[$key] = new CacheDefinition(
            method: $method,
            policy: $attributes === [] ? null : $attributes[0]->newInstance()->policy(),
        );
    }
}
