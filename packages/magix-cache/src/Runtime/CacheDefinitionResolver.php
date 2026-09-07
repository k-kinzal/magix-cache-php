<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use InvalidArgumentException;
use LogicException;
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Attribute\UseStrategy;
use ReflectionException;
use ReflectionMethod;

/**
 * Resolves and memoizes reflected cache declarations.
 *
 * Only the static declaration is memoized, indexed by concrete class and
 * method; per-invocation data never enters this cache. A behavior declared
 * with enabled: false resolves to no behavior, which is how a method disables
 * a class-level default.
 *
 * @internal
 */
final class CacheDefinitionResolver
{
    /**
     * Resolved cache definitions indexed by concrete class and method.
     *
     * @var array<string, CacheDefinition>
     */
    private array $definitions = [];

    /**
     * Returns the resolved declaration of one cache boundary.
     *
     * @throws LogicException when the boundary cannot be reflected, declares no #[Cache], or repeats a declaration
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
            throw new LogicException($key.' is not a method that can be reflected.', previous: $missing);
        }

        $reader = new CacheAttributeReader();
        $declaration = $reader->read($service, $methodName, Cache::class)
            ?? throw new LogicException($key.' declares no #[Cache] on the method or its concrete class.');

        $staleIfError = $reader->read($service, $methodName, StaleIfError::class);
        $dynamicTtl = $reader->read($service, $methodName, DynamicTtl::class);
        $bypassCacheErrors = $reader->read($service, $methodName, BypassCacheErrors::class);
        $useStrategy = $reader->read($service, $methodName, UseStrategy::class);

        return $this->definitions[$key] = new CacheDefinition(
            method: $method,
            concreteClass: $service::class,
            declaration: $declaration,
            staleIfError: $staleIfError?->enabled === true ? $staleIfError : null,
            dynamicTtl: $dynamicTtl?->enabled === true ? $dynamicTtl : null,
            bypassCacheErrors: $bypassCacheErrors?->enabled === true ? $bypassCacheErrors : null,
            useStrategy: $useStrategy?->enabled === true ? $useStrategy : null,
        );
    }
}
