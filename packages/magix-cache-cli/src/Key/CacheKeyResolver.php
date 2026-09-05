<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Key;

use function class_exists;
use function count;

use Magix\Cache\Runtime\CacheDefinition;
use Magix\Cache\Runtime\CacheDefinitionResolver;
use Magix\Cache\Runtime\CacheKeyArgumentBinder;
use Magix\Cache\Runtime\KeyStrategy\HashCacheKeyStrategy;
use ReflectionClass;
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
        private CacheDefinitionResolver $definitions = new CacheDefinitionResolver(),
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
     * Returns the resolved declaration the runtime would derive the key from.
     *
     * The declaration is resolved exactly like at runtime, on an instance
     * created without its constructor, so the key carries the same version
     * and declaration fingerprint the runtime stores entries under. A
     * boundary that declares no #[Cache] is the caller's mistake and is
     * checked before this method runs; here it surfaces as the same
     * LogicException the runtime raises.
     *
     * @throws CacheKeyUnresolvable when the class cannot be loaded in this process
     */
    public function definition(string $class, string $method): CacheDefinition
    {
        if (!class_exists($class)) {
            throw new CacheKeyUnresolvable(
                'The class '.$class.' cannot be loaded. Run magix where the project autoloader can reach it.',
            );
        }

        return $this->definitions->resolve(new ReflectionClass($class)->newInstanceWithoutConstructor(), $method);
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
    public function resolve(string $class, string $method, array $arguments): string
    {
        $this->arguments($class, $method, $arguments);

        return $this->strategy->generate($this->definition($class, $method)->keyContext($arguments));
    }
}
