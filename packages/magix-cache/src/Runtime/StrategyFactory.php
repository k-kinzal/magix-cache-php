<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Magix\Cache\Observation\CacheObserver;
use Magix\Cache\Strategy\CacheStrategy;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Supplies runtime dependencies when constructing an invocation's middleware.
 *
 * Configuration stays in StrategyDefinition. Missing constructor parameters
 * typed ClockInterface or CacheObserver receive this runtime's dependencies.
 * There is no dependency context in the operation or continuation contracts.
 *
 * @internal
 */
final readonly class StrategyFactory
{
    /**
     * Supplies the clock and optional observer used by this runtime.
     */
    public function __construct(private ClockInterface $clock, private ?CacheObserver $observer)
    {
    }

    /**
     * Constructs one middleware with its explicit configuration and dependencies.
     *
     * @param class-string<CacheStrategy> $class
     * @param array<array-key, mixed> $arguments
     */
    public function create(string $class, array $arguments): CacheStrategy
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $arguments) || array_key_exists($parameter->getPosition(), $arguments)
                || !$type instanceof ReflectionNamedType || $parameter->isVariadic()) {
                continue;
            }

            if ($type->getName() === ClockInterface::class) {
                $arguments[$name] = $this->clock;
            } elseif ($type->getName() === CacheObserver::class && ($this->observer !== null || $type->allowsNull())) {
                $arguments[$name] = $this->observer;
            }
        }

        return new $class(...$arguments);
    }
}
