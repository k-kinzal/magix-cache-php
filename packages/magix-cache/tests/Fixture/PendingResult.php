<?php

declare(strict_types=1);

namespace Tests\Fixture;

use GuzzleHttp\Promise\Promise as GuzzlePromise;
use Magix\Cache\Async\Promise;
use RuntimeException;

/**
 * A controllable asynchronous source whose wait driver is observable.
 *
 * @template T
 */
final class PendingResult
{
    private GuzzlePromise $transport;

    /**
     * Number of explicit blocking waits.
     */
    public int $waits = 0;

    /**
     * @param T $value
     */
    public function __construct(private readonly mixed $value)
    {
        $this->transport = new GuzzlePromise(function (): void {
            ++$this->waits;
            $this->complete();
        });
    }

    /**
     * @return Promise<T>
     */
    public function promise(): Promise
    {
        /**
         * @var Promise<T> $promise The fixture resolves exactly its constructor value.
         */
        $promise = Promise::fromGuzzle($this->transport);

        return $promise;
    }

    /**
     * Completes the source without running the wait driver.
     */
    public function complete(): void
    {
        $this->transport->resolve($this->value);
    }

    /**
     * Rejects the source with a declared failure.
     */
    public function fail(RuntimeException $error): void
    {
        $this->transport->reject($error);
    }
}
