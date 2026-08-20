<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Key;

use function count;

use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\CacheKeyContext;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use ReflectionException;
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
     * Returns the reflected boundary, which the scanned sources only named.
     *
     * @throws CacheKeyUnresolvable when the boundary cannot be loaded in this process
     */
    public function reflect(string $class, string $method): ReflectionMethod
    {
        try {
            return new ReflectionMethod($class, $method);
        } catch (ReflectionException $missing) {
            throw new CacheKeyUnresolvable(
                'The boundary '.$class.'::'.$method.' cannot be loaded. Run magix where the project autoloader can reach it.',
                previous: $missing,
            );
        }
    }

    /**
     * Returns the arguments after ignored and reduced parameters are applied.
     *
     * @param list<mixed> $arguments
     * @return array<string, mixed>
     * @throws CacheKeyUnresolvable when the boundary cannot be loaded or the call does not match its parameters
     */
    public function arguments(string $class, string $method, array $arguments): array
    {
        $reflection = $this->reflect($class, $method);
        $given = count($arguments);
        $required = $reflection->getNumberOfRequiredParameters();

        if ($given < $required) {
            throw new CacheKeyUnresolvable(
                $reflection->getName().' needs '.$required.' argument(s) to be keyed, but '.$given.' were given.',
            );
        }

        if (!$reflection->isVariadic() && $given > $reflection->getNumberOfParameters()) {
            throw new CacheKeyUnresolvable(
                $reflection->getName().' declares '.$reflection->getNumberOfParameters().' parameter(s), but '.$given.' arguments were given.',
            );
        }

        return $this->binder->bind($reflection, $arguments);
    }

    /**
     * Returns the key the default strategy derives for one call.
     *
     * @param list<mixed> $arguments
     * @throws CacheKeyUnresolvable when the boundary cannot be loaded or the call does not match its parameters
     */
    public function resolve(string $class, string $method, string $version, array $arguments): string
    {
        $reflection = $this->reflect($class, $method);

        return $this->strategy->generate(new CacheKeyContext(
            class: $reflection->getDeclaringClass()->getName(),
            method: $reflection->getName(),
            arguments: $this->arguments($class, $method, $arguments),
            version: $version,
        ));
    }
}
