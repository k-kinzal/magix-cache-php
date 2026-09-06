<?php

declare(strict_types=1);

namespace Magix\Cache;

use function array_key_exists;

use BadMethodCallException;
use Closure;

use function get_object_vars;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;

use LogicException;
use Magix\Cache\Composition\Capability2;
use Magix\Cache\Composition\Capability3;
use Magix\Cache\Composition\Capability4;
use Magix\Cache\Composition\Capability5;
use Magix\Cache\Metadata\CacheMetadata;
use Stringable;

/**
 * Carries an evaluated value together with its cache constraints.
 *
 * The composition API preserves the constraints of every dependency it is
 * given: map keeps this value's metadata, flatMap and combineN meet the
 * metadata of all inputs. Extracting a value with value() detaches it from
 * its constraints, so a dependency obtained inside map must be chained with
 * flatMap or combineN instead.
 *
 * @template-covariant T
 * @mixin T
 */
final readonly class Cached
{
    /**
     * Creates a cached value.
     *
     * @param T $value
     */
    public function __construct(
        private mixed $value,
        public CacheMetadata $metadata,
    ) {
    }

    /**
     * Wraps an evaluated value and the constraints its producer declares.
     *
     * Omitting the metadata declares no additional constraints; storage
     * eligibility is still judged after policy application.
     *
     * @template V
     * @param V $value
     * @return self<V>
     */
    public static function of(mixed $value, ?CacheMetadata $metadata = null): self
    {
        return new self($value, $metadata ?? CacheMetadata::top());
    }

    /**
     * Returns the wrapped value for scalar operations or typed arguments.
     *
     * The returned value no longer carries these constraints.
     *
     * @return T
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Transforms the value once, in place, and keeps these constraints.
     *
     * The result of the transform is treated as a plain value: a Cached
     * returned from it is not flattened. Use flatMap for a new dependency.
     *
     * @template U
     * @param Closure(T): U $transform
     * @return self<U>
     */
    public function map(Closure $transform): self
    {
        return new self($transform($this->value), $this->metadata);
    }

    /**
     * Obtains the next result now and composes both sets of constraints.
     *
     * @template U
     * @param Closure(T): self<U> $transform
     * @return self<U>
     */
    public function flatMap(Closure $transform): self
    {
        $next = $transform($this->value);

        return new self($next->value, $this->metadata->meet($next->metadata));
    }

    /**
     * Combines this value with one dependency.
     *
     * @template T2
     * @param self<T2> $second
     * @return Capability2<T, T2>
     */
    public function combine2(self $second): Capability2
    {
        return new Capability2($this, $second);
    }

    /**
     * Combines this value with two dependencies.
     *
     * @template T2
     * @template T3
     * @param self<T2> $second
     * @param self<T3> $third
     * @return Capability3<T, T2, T3>
     */
    public function combine3(self $second, self $third): Capability3
    {
        return new Capability3($this, $second, $third);
    }

    /**
     * Combines this value with three dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @return Capability4<T, T2, T3, T4>
     */
    public function combine4(self $second, self $third, self $fourth): Capability4
    {
        return new Capability4($this, $second, $third, $fourth);
    }

    /**
     * Combines this value with four dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @param self<T5> $fifth
     * @return Capability5<T, T2, T3, T4, T5>
     */
    public function combine5(self $second, self $third, self $fourth, self $fifth): Capability5
    {
        return new Capability5($this, $second, $third, $fourth, $fifth);
    }

    /**
     * Forwards inaccessible property reads to an object or string-keyed array.
     *
     * Forwarding is for final observation only: the value it reaches no longer
     * carries these constraints. Cached's own members take precedence.
     *
     * @throws LogicException when the wrapped value exposes no such property
     */
    public function __get(string $name): mixed
    {
        if (is_array($this->value) && array_key_exists($name, $this->value)) {
            return $this->value[$name];
        }

        if (!is_object($this->value)) {
            throw new LogicException('Cannot read $'.$name.' from a cached non-object value.');
        }

        $properties = get_object_vars($this->value);

        if (!array_key_exists($name, $properties)) {
            throw new LogicException('Cached object has no public property $'.$name.'.');
        }

        return $properties[$name];
    }

    /**
     * Forwards isset() checks to an object or string-keyed array.
     */
    public function __isset(string $name): bool
    {
        if (is_array($this->value)) {
            return isset($this->value[$name]);
        }

        return is_object($this->value) && isset(get_object_vars($this->value)[$name]);
    }

    /**
     * Forwards unknown method calls to the wrapped object.
     *
     * @param list<mixed> $arguments
     * @throws BadMethodCallException when the wrapped value exposes no such method
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!is_object($this->value) || !is_callable([$this->value, $name])) {
            throw new BadMethodCallException('Cached value cannot handle method '.$name.'().');
        }

        return Closure::fromCallable([$this->value, $name])(...$arguments);
    }

    /**
     * Forwards string conversion to a string or Stringable wrapped value.
     *
     * @throws LogicException when the wrapped value has no string representation
     */
    public function __toString(): string
    {
        if (is_string($this->value)) {
            return $this->value;
        }

        if ($this->value instanceof Stringable) {
            return (string) $this->value;
        }

        throw new LogicException('Cached value cannot be converted to string.');
    }
}
