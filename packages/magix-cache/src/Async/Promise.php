<?php

declare(strict_types=1);

namespace Magix\Cache\Async;

use Closure;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\Utils;
use RuntimeException;
use Throwable;

/**
 * A typed promise with Guzzle scheduling, adoption and synchronous waiting.
 *
 * The transport promise never leaves this adapter. Returning a Promise from
 * then adopts its eventual value; callbacks run once through Guzzle's queue.
 * Rejections preserve their original throwable, including programming errors.
 *
 * @template-covariant T
 */
final readonly class Promise
{
    /**
     * Adapts a transport without waiting or changing its cancellation semantics.
     *
     * @param PromiseInterface<covariant T, covariant mixed> $promise
     */
    public function __construct(private PromiseInterface $promise)
    {
    }

    /**
     * Imports a Guzzle promise without evaluating or waiting for it.
     *
     * @template V
     * @param PromiseInterface<covariant V, covariant mixed> $promise
     * @return self<V>
     */
    public static function fromGuzzle(PromiseInterface $promise): self
    {
        return new self($promise);
    }

    /**
     * Creates an already fulfilled promise, including a null unit value.
     *
     * @template V
     * @param V $value
     * @return self<V>
     */
    public static function resolved(mixed $value = null): self
    {
        return new self(new FulfilledPromise($value));
    }

    /**
     * Creates a rejected promise without translating the failure.
     *
     * @return self<never>
     */
    public static function rejected(Throwable $error): self
    {
        return new self(new RejectedPromise($error));
    }

    /**
     * Schedules a computation, adopting its promise and capturing its failure.
     *
     * @template V
     * @param Closure(): (V|self<V>) $compute
     * @return self<V>
     */
    public static function call(Closure $compute): self
    {
        return self::resolved()->then(static fn (): mixed => $compute());
    }

    /**
     * Bridges callback completion and an explicit synchronous waiting boundary.
     *
     * Neither callback registration nor constructing this bridge waits. This
     * lets an AsyncCached participate without exposing its private promise.
     *
     * @template V
     * @param Closure(): V $wait
     * @param Closure(Closure(V): void, Closure(Throwable): void): void $subscribe
     * @return self<V>
     */
    public static function bridge(Closure $wait, Closure $subscribe): self
    {
        /** @var GuzzlePromise<V> $transport */
        $transport = new GuzzlePromise(static function () use (&$transport, $wait): void {
            /** @var GuzzlePromise<V> $transport The wait driver runs after construction. */
            /**
             * Subscription may settle the bridge while the wait driver pumps
             * the queue. Capture its rejection in a task so Guzzle cannot
             * mistake that same rejection for a failure after settlement.
             */
            Utils::task($wait)->then($transport->resolve(...), $transport->reject(...))->wait(false);
        });
        $subscribe($transport->resolve(...), $transport->reject(...));

        return new self($transport);
    }

    /**
     * Continues after fulfillment or rejection and adopts a returned promise.
     *
     * Exceptions thrown by either callback reject the returned promise.
     *
     * @template U
     * @param Closure(T): (U|self<U>) $fulfilled
     * @param (Closure(mixed): (U|self<U>))|null $rejected Guzzle permits non-throwable rejection reasons.
     * @return self<U>
     */
    public function then(Closure $fulfilled, ?Closure $rejected = null): self
    {
        /** @var PromiseInterface<U> $chained Guzzle adopts the unwrapped callback result. */
        $chained = $this->promise->then(
            /** @param T $value
             * @return U|PromiseInterface<covariant U, covariant mixed>
             */
            static function (mixed $value) use ($fulfilled): mixed {
                $result = $fulfilled($value);

                return $result instanceof self ? $result->promise : $result;
            },
            $rejected === null ? null : /** @return U|PromiseInterface<covariant U, covariant mixed> */ static function (mixed $reason) use ($rejected): mixed {
                $result = $rejected($reason);

                return $result instanceof self ? $result->promise : $result;
            },
        );

        return new self($chained);
    }

    /**
     * Recovers only declared runtime failures, preserving every other rejection.
     *
     * @template U
     * @param Closure(RuntimeException): (U|self<U>) $recover
     * @return self<T|U>
     */
    public function recover(Closure $recover): self
    {
        return $this->then(static fn (mixed $value): mixed => $value, static function (mixed $reason) use ($recover): mixed {
            return $reason instanceof RuntimeException ? $recover($reason) : new self(new RejectedPromise($reason));
        });
    }

    /**
     * Collects already-started promises without waiting during composition.
     *
     * @template V
     * @param list<self<V>> $promises
     * @return self<list<V>>
     */
    public static function all(array $promises): self
    {
        /** @var list<PromiseInterface<V>> $transports Utils::all only observes these transports. */
        $transports = array_map(static fn (self $promise): PromiseInterface => $promise->promise, $promises);

        /** @var PromiseInterface<list<V>> $all The list input is restored after Guzzle's keyed aggregation. */
        $all = Utils::all($transports)->then(static fn (array $values): array => array_values($values));

        return new self($all);
    }

    /**
     * Blocks until completion, returning the value or rethrowing the rejection.
     *
     * @return T
     * @throws RuntimeException when a delegated operation rejects with a declared failure
     */
    public function wait(): mixed
    {
        return $this->promise->wait();
    }
}
