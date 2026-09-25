<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;
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
    public static function compose(self ...$definitions): self
    {
        return new self(ComposedCacheStrategy::class, ...$definitions);
    }

    /**
     * Constructs a fresh execution, including every nested child.
     *
     * The optional factory supplies constructor dependencies without storing
     * them in the immutable definition. It is forwarded to every nested child.
     *
     * @param Closure(class-string<CacheStrategy>, array<array-key, mixed>): CacheStrategy|null $factory
     */
    public function instantiate(?Closure $factory = null): CacheStrategy
    {
        $arguments = (new StrategyArguments())->instantiate($this->arguments, $factory);

        return $factory === null ? new ($this->class)(...$arguments) : $factory($this->class, $arguments);
    }
}
