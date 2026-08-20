<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Key;

use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use ReflectionMethod;

/**
 * Derives the cache key of a call the way the default runtime does.
 */
final readonly class CacheKeyResolver
{
    /**
     * Creates a key resolver.
     */
    public function __construct(
        private CacheKeyArgumentBinder $binder = new CacheKeyArgumentBinder(),
        private HashCacheKeyStrategy $strategy = new HashCacheKeyStrategy(),
    ) {
    }

    /**
     * Returns the arguments after ignored and reduced parameters are applied.
     *
     * @param list<mixed> $arguments
     * @return array<string, mixed>
     */
    public function arguments(string $class, string $method, array $arguments): array
    {
        return $this->binder->bind(new ReflectionMethod($class, $method), $arguments);
    }

    /**
     * Returns the key the default strategy derives for one call.
     *
     * @param list<mixed> $arguments
     */
    public function resolve(string $class, string $method, string $version, array $arguments): string
    {
        $reflection = new ReflectionMethod($class, $method);

        return $this->strategy->generate(new CacheKeyContext(
            class: $reflection->getDeclaringClass()->getName(),
            method: $reflection->getName(),
            arguments: $this->arguments($class, $method, $arguments),
            version: $version,
        ));
    }
}
