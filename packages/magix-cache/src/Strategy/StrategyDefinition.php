<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use InvalidArgumentException;

use function is_a;

use ReflectionClass;

/**
 * Describes construction without retaining an executable strategy instance.
 *
 * Definitions can be memoized and composed again. Each instantiate() uses
 * new for every leaf and composition. Arguments contain configuration values
 * and nested definitions; executable objects and closures are never retained.
 */
final readonly class StrategyDefinition
{
    /** @var class-string<CacheStrategy> */
    private string $class;

    /** @var array<array-key, mixed> */
    private array $arguments;

    /**
     * Declares a constructible strategy and copies its configuration values.
     *
     * @throws InvalidArgumentException when the class or configuration is invalid
     */
    public function __construct(string $class, mixed ...$arguments)
    {
        if (!is_a($class, CacheStrategy::class, true) || !(new ReflectionClass($class))->isInstantiable()) {
            throw new InvalidArgumentException('A strategy definition requires a constructible CacheStrategy class.');
        }

        $this->class = $class;
        $this->arguments = (new StrategyArguments())->copy($arguments);
    }

    /**
     * Describes a strategy using its class and named constructor arguments.
     *
     * @throws InvalidArgumentException when the class or configuration is invalid
     */
    public static function of(string $class, mixed ...$arguments): self
    {
        return new self($class, ...$arguments);
    }

    /**
     * Composes definitions in delegation order without constructing them.
     */
    public static function compose(self $first, self ...$rest): self
    {
        return new self(ComposedCacheStrategy::class, $first, ...$rest);
    }

    /**
     * Constructs a fresh execution, including every nested child.
     */
    public function instantiate(): CacheStrategy
    {
        $arguments = (new StrategyArguments())->instantiate($this->arguments);

        return new ($this->class)(...$arguments);
    }
}
