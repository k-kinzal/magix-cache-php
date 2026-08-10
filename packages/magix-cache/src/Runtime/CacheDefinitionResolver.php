<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Attribute\Cache;
use ReflectionClass;
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
     */
    public function resolve(object $service, string $methodName): CacheDefinition
    {
        $key = $service::class.'::'.$methodName;

        if (isset($this->definitions[$key])) {
            return $this->definitions[$key];
        }

        $method = new ReflectionMethod($service, $methodName);
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
