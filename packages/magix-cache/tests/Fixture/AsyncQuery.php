<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\AsyncCached;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\StaleIfError;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * A deferred source behind synchronous and asynchronous boundaries.
 */
final class AsyncQuery
{
    use Cacheable;

    /**
     * Number of origin invocations.
     */
    public int $calls = 0;

    /**
     * @param PendingResult<string> $source
     */
    public function __construct(public PendingResult $source)
    {
    }

    /**
     * @return AsyncCached<string>
     */
    #[Cache(ttl: 20)]
    #[StaleIfError(maxAge: 60, exceptions: [UpstreamUnavailable::class])]
    public function fetch(int $id = 1): AsyncCached
    {
        return $this->asyncCached(function (): AsyncCached {
            ++$this->calls;

            return AsyncCached::fromPromise($this->source->promise(), new CacheMetadata(tags: ['source'], reasons: ['origin']));
        });
    }

    /**
     * @return Cached<string>
     */
    #[Cache(ttl: 30)]
    public function synchronous(): Cached
    {
        return $this->cached(fn (): AsyncCached => AsyncCached::fromPromise($this->source->promise()));
    }

    /**
     * @return AsyncCached<string>
     */
    #[Cache(ttl: 30)]
    public function immediate(): AsyncCached
    {
        return $this->asyncCached(static fn (): Cached => Cached::of('immediate'));
    }

    /**
     * @return AsyncCached<string>
     */
    #[Cache(ttl: 30)]
    #[UseStrategy(ImmediateStrategy::class)]
    public function shortCircuit(): AsyncCached
    {
        return $this->asyncCached(function (): AsyncCached {
            ++$this->calls;

            return AsyncCached::fromPromise($this->source->promise());
        });
    }

    /**
     * @return AsyncCached<array{string, string}>
     */
    #[Cache]
    public function composed(): AsyncCached
    {
        return $this->asyncCached(fn (): AsyncCached => $this->fetch(1)->zip($this->fetch(2)));
    }
}
