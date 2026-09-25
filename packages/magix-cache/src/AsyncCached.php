<?php

declare(strict_types=1);

namespace Magix\Cache;

use Closure;
use Generator;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Magix\Cache\Async\Promise;
use Magix\Cache\Composition\AsyncCombinations;
use Magix\Cache\Metadata\CacheMetadata;
use RuntimeException;
use Throwable;

/**
 * An eventual value with cache metadata, never a promise-valued payload.
 *
 * Composition registers continuations without waiting. toCached() and value()
 * are the synchronous boundaries. map preserves nested Cached values;
 * flatMap and flatten meet dependencies and remove exactly one wrapper.
 *
 * @template-covariant T
 */
final readonly class AsyncCached
{
    /** @use AsyncCombinations<T> */
    use AsyncCombinations;

    /** @var Promise<Cached<T>> */
    private Promise $promise;

    /**
     * Takes ownership of a promise of the complete evaluated cache result.
     *
     * @param Promise<Cached<T>> $promise
     */
    public function __construct(Promise $promise)
    {
        $this->promise = $promise->then(static function (Cached $cached): Cached {
            self::checkValue($cached->value());

            return $cached;
        });
    }

    /**
     * Lifts a plain value without unwrapping a nested Cached or AsyncCached.
     *
     * @template V
     * @param V $value
     * @return self<V>
     * @throws LogicException when a promise is used as the payload instead of as the computation
     */
    public static function of(mixed $value, ?CacheMetadata $metadata = null): self
    {
        self::checkValue($value);

        return self::fromCached(Cached::of($value, $metadata));
    }

    /**
     * Lifts a Cached result, preserving its value and complete metadata.
     *
     * @template V
     * @param Cached<V> $cached
     * @return self<V>
     */
    public static function fromCached(Cached $cached): self
    {
        return self::fromCachedPromise(Promise::resolved($cached));
    }

    /**
     * Wraps an eventual payload; an eventual Cached stays a nested value.
     *
     * @template V
     * @param Promise<V> $promise
     * @return self<V>
     */
    public static function fromPromise(Promise $promise, ?CacheMetadata $metadata = null): self
    {
        return self::fromCachedPromise($promise->then(static fn (mixed $value): Cached => Cached::of($value, $metadata)));
    }

    /**
     * Imports an asynchronous Guzzle result without leaking its promise.
     *
     * @template V
     * @param PromiseInterface<covariant V, covariant mixed> $promise
     * @return self<V>
     */
    public static function fromGuzzle(PromiseInterface $promise, ?CacheMetadata $metadata = null): self
    {
        return self::fromPromise(Promise::fromGuzzle($promise), $metadata);
    }

    /**
     * Accepts the runtime's eventual Cached result without adding a value layer.
     *
     * @template V
     * @param Promise<Cached<V>> $promise
     * @return self<V>
     * @internal
     */
    public static function fromCachedPromise(Promise $promise): self
    {
        return new self($promise);
    }

    /**
     * Waits for the complete value and metadata, removing only the async layer.
     *
     * @return Cached<T>
     * @throws RuntimeException when the computation or its cache write fails
     */
    public function toCached(): Cached
    {
        return $this->promise->wait();
    }

    /**
     * Waits and extracts the payload, detaching its cache constraints.
     *
     * @return T
     * @throws RuntimeException when the computation or its cache write fails
     */
    public function value(): mixed
    {
        return $this->toCached()->value();
    }

    /**
     * Registers completion callbacks without exposing the owned promise.
     *
     * Runtime bridges use these callbacks with toCached as their wait driver.
     *
     * @param Closure(Cached<T>): void $fulfilled
     * @param Closure(Throwable): void $rejected
     * @internal
     */
    public function subscribe(Closure $fulfilled, Closure $rejected): void
    {
        $this->promise->then($fulfilled, static function (mixed $reason) use ($rejected): void {
            $rejected($reason instanceof Throwable ? $reason : new \GuzzleHttp\Promise\RejectionException($reason));
        });
    }

    /**
     * Maps the eventual payload once and preserves this result's metadata.
     *
     * @template U
     * @param Closure(T): U $transform
     * @return self<U>
     */
    public function map(Closure $transform): self
    {
        return self::fromCachedPromise($this->promise->then(static fn (Cached $cached): Cached => $cached->map($transform)));
    }

    /**
     * Obtains a dependency asynchronously and meets both sets of constraints.
     *
     * @template U
     * @param Closure(T): self<U> $transform
     * @return self<U>
     */
    public function flatMap(Closure $transform): self
    {
        return self::fromCachedPromise($this->promise->then(static function (Cached $cached) use ($transform): Promise {
            $next = $transform($cached->value());

            return $next->promise->then(static fn (Cached $result): Cached => Cached::of($result->value(), $cached->metadata->meet($result->metadata)));
        }));
    }

    /**
     * Removes exactly one AsyncCached layer and meets both sets of metadata.
     *
     * For AsyncCached<Cached<T>>, use toCached()->flatten() instead.
     *
     * @return self<template-type<T, self, 'T'>>
     * @throws LogicException when the eventual payload is not an AsyncCached
     */
    public function flatten(): self
    {
        return $this->flatMap(static function (mixed $value): self {
            if (!$value instanceof self) {
                throw new LogicException('flatten() requires an AsyncCached value inside this AsyncCached result.');
            }

            return $value;
        });
    }

    /**
     * Pairs independently running computations and meets their metadata.
     *
     * @template U
     * @param self<U> $other
     * @return self<array{T, U}>
     */
    public function zip(self $other): self
    {
        return self::fromCachedPromise($this->promise->then(static fn (Cached $first): Promise => $other->promise->then(
            static fn (Cached $second): Cached => $first->zip($second),
        )));
    }

    /**
     * Splits a pair asynchronously, keeping all pair metadata on each projection.
     *
     * @return array{self<T[0]>, self<T[1]>}
     */
    public function unzip(): array
    {
        $pair = $this->promise->then(static fn (Cached $cached): array => $cached->unzip());

        return [self::fromCachedPromise($pair->then(static fn (array $items): Cached => $items[0])), self::fromCachedPromise($pair->then(static fn (array $items): Cached => $items[1]))];
    }

    /**
     * Consumes the iterable now and resolves all items without blocking here.
     *
     * Keys and insertion order are preserved; overwritten items still meet
     * their constraints. Empty input has top() metadata.
     *
     * @template K of array-key
     * @template U = never
     * @param iterable<K, self<U>> $items
     * @return self<array<K, U>>
     */
    public static function sequence(iterable $items): self
    {
        $keys = [];
        $promises = [];

        foreach ($items as $key => $item) {
            $keys[] = $key;
            $promises[] = $item->promise;
        }

        return self::fromCachedPromise(Promise::all($promises)->then(static function (array $results) use ($keys): Cached {
            $values = [];
            $metadata = CacheMetadata::top();

            foreach ($results as $index => $result) {
                $values[$keys[$index]] = $result->value();
                $metadata = $metadata->meet($result->metadata);
            }

            return Cached::of($values, $metadata);
        }));
    }

    /**
     * Starts each computation once, then collects its eventual value and metadata.
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
        return self::sequence((static function () use ($items, $transform): Generator {
            foreach ($items as $key => $item) {
                yield $key => $transform($item);
            }
        })());
    }

    /**
     * Enforces that a computation never becomes a promise-valued payload.
     *
     * @throws LogicException when a promise is passed as a plain payload
     */
    private static function checkValue(mixed $value): void
    {
        if ($value instanceof Promise || $value instanceof PromiseInterface) {
            throw new LogicException('An AsyncCached payload cannot be a promise; use fromPromise() or fromGuzzle().');
        }
    }
}
