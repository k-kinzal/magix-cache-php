<?php

declare(strict_types=1);

namespace Magix\Cache;

use function array_is_list;

use Closure;

use function count;
use function is_array;

use LogicException;
use Magix\Cache\Composition\Capability10;
use Magix\Cache\Composition\Capability2;
use Magix\Cache\Composition\Capability3;
use Magix\Cache\Composition\Capability4;
use Magix\Cache\Composition\Capability5;
use Magix\Cache\Composition\Capability6;
use Magix\Cache\Composition\Capability7;
use Magix\Cache\Composition\Capability8;
use Magix\Cache\Composition\Capability9;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * Carries an evaluated value together with its cache constraints.
 *
 * The composition API preserves the constraints of every dependency it is
 * given: map and unzip keep this value's metadata; flatMap, flatten, zip,
 * sequence, traverse and combineN meet the metadata of all inputs. Extracting
 * a value with value() detaches it from its constraints, so a dependency
 * obtained inside map must be chained with flatMap or combineN instead.
 *
 * @template-covariant T
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
     * Transforms the value now, once, and keeps these constraints.
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
     * Removes exactly one Cached layer and meets both sets of constraints.
     *
     * Cached<Cached<U>> becomes Cached<U>; deeper layers remain wrapped.
     *
     * @return self<template-type<T, self, 'T'>>
     * @throws LogicException when the wrapped value is not a Cached result
     */
    public function flatten(): self
    {
        $inner = $this->value;

        if (!$inner instanceof self) {
            throw new LogicException('flatten() requires a Cached value inside this Cached result.');
        }

        return new self($inner->value, $this->metadata->meet($inner->metadata));
    }

    /**
     * Pairs two values while meeting the constraints of both dependencies.
     *
     * @template U
     * @param self<U> $other
     * @return self<array{T, U}>
     */
    public function zip(self $other): self
    {
        return new self([$this->value, $other->value], $this->metadata->meet($other->metadata));
    }

    /**
     * Splits a pair into two cached values, each keeping all its constraints.
     *
     * Neither projection can recover the weaker metadata from before zip().
     *
     * @return array{self<T[0]>, self<T[1]>}
     * @throws LogicException when the wrapped value is not a two-element list
     */
    public function unzip(): array
    {
        $pair = $this->value;

        if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) {
            throw new LogicException('unzip() requires a two-element list inside this Cached result.');
        }

        return [new self($pair[0], $this->metadata), new self($pair[1], $this->metadata)];
    }

    /**
     * Collects cached values into one array and meets every item's constraints.
     *
     * The iterable is consumed now, once, preserving its keys. Repeated keys
     * keep the last value, but the constraints of every item still contribute.
     * Empty input produces an empty array with top() metadata.
     *
     * @template K of array-key
     * @template U = never
     * @param iterable<K, self<U>> $items
     * @return self<array<K, U>>
     */
    public static function sequence(iterable $items): self
    {
        $values = [];
        $metadata = CacheMetadata::top();

        foreach ($items as $key => $item) {
            $values[$key] = $item->value;
            $metadata = $metadata->meet($item->metadata);
        }

        return new self($values, $metadata);
    }

    /**
     * Maps each input to a Cached result and collects their met constraints.
     *
     * Evaluation is eager, once per item in iteration order, preserving keys.
     * Repeated keys keep the last value and all constraints. Empty input
     * produces an empty array with top() metadata without calling transform.
     *
     * @template K of array-key
     * @template V
     * @template U
     * @param iterable<K, V> $items
     * @param Closure(V): self<U> $transform
     * @return self<array<K, U>>
     */
    public static function traverse(iterable $items, Closure $transform): self
    {
        $values = [];
        $metadata = CacheMetadata::top();

        foreach ($items as $key => $item) {
            $next = $transform($item);
            $values[$key] = $next->value;
            $metadata = $metadata->meet($next->metadata);
        }

        return new self($values, $metadata);
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
     * Combines this value with five dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @param self<T5> $fifth
     * @param self<T6> $sixth
     * @return Capability6<T, T2, T3, T4, T5, T6>
     */
    public function combine6(self $second, self $third, self $fourth, self $fifth, self $sixth): Capability6
    {
        return new Capability6($this, $second, $third, $fourth, $fifth, $sixth);
    }

    /**
     * Combines this value with six dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @param self<T5> $fifth
     * @param self<T6> $sixth
     * @param self<T7> $seventh
     * @return Capability7<T, T2, T3, T4, T5, T6, T7>
     */
    public function combine7(self $second, self $third, self $fourth, self $fifth, self $sixth, self $seventh): Capability7
    {
        return new Capability7($this, $second, $third, $fourth, $fifth, $sixth, $seventh);
    }

    /**
     * Combines this value with seven dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @template T8
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @param self<T5> $fifth
     * @param self<T6> $sixth
     * @param self<T7> $seventh
     * @param self<T8> $eighth
     * @return Capability8<T, T2, T3, T4, T5, T6, T7, T8>
     */
    public function combine8(self $second, self $third, self $fourth, self $fifth, self $sixth, self $seventh, self $eighth): Capability8
    {
        return new Capability8($this, $second, $third, $fourth, $fifth, $sixth, $seventh, $eighth);
    }

    /**
     * Combines this value with eight dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @template T8
     * @template T9
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @param self<T5> $fifth
     * @param self<T6> $sixth
     * @param self<T7> $seventh
     * @param self<T8> $eighth
     * @param self<T9> $ninth
     * @return Capability9<T, T2, T3, T4, T5, T6, T7, T8, T9>
     */
    public function combine9(self $second, self $third, self $fourth, self $fifth, self $sixth, self $seventh, self $eighth, self $ninth): Capability9
    {
        return new Capability9($this, $second, $third, $fourth, $fifth, $sixth, $seventh, $eighth, $ninth);
    }

    /**
     * Combines this value with nine dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @template T8
     * @template T9
     * @template T10
     * @param self<T2> $second
     * @param self<T3> $third
     * @param self<T4> $fourth
     * @param self<T5> $fifth
     * @param self<T6> $sixth
     * @param self<T7> $seventh
     * @param self<T8> $eighth
     * @param self<T9> $ninth
     * @param self<T10> $tenth
     * @return Capability10<T, T2, T3, T4, T5, T6, T7, T8, T9, T10>
     */
    public function combine10(self $second, self $third, self $fourth, self $fifth, self $sixth, self $seventh, self $eighth, self $ninth, self $tenth): Capability10
    {
        return new Capability10($this, $second, $third, $fourth, $fifth, $sixth, $seventh, $eighth, $ninth, $tenth);
    }
}
