<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\AsyncComposition;

use Magix\Cache\AsyncCached;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

/**
 * Boundaries whose asynchronous structure must not change metadata analysis.
 */
final class AsyncQueries
{
    use Cacheable;

    /**
     * @return AsyncCached<int>
     */
    #[Cache(ttl: 20, tags: ['a'])]
    public function first(): AsyncCached
    {
        return $this->asyncCached(static fn (): AsyncCached => AsyncCached::of(1));
    }

    /**
     * @return AsyncCached<int>
     */
    #[Cache(ttl: 60, visibility: Visibility::Private, tags: ['b'])]
    public function second(): AsyncCached
    {
        return $this->asyncCached(static fn (): AsyncCached => AsyncCached::of(2));
    }

    /**
     * @return AsyncCached<array{int, int}>
     */
    #[Cache]
    public function composed(): AsyncCached
    {
        return $this->asyncCached(fn (): AsyncCached => $this->first()->zip($this->second()));
    }

    /**
     * @return Cached<array{int, int}>
     */
    #[Cache]
    public function synchronized(): Cached
    {
        return $this->cached(fn (): Cached => $this->first()->zip($this->second())->toCached());
    }

    /**
     * @return AsyncCached<int>
     */
    #[Cache]
    public function lifted(): AsyncCached
    {
        return $this->asyncCached(fn (): AsyncCached => AsyncCached::fromCached($this->first()->toCached()));
    }

    /**
     * @return Cached<int>
     */
    #[Cache]
    public function nested(): Cached
    {
        return $this->cached(fn (): Cached => AsyncCached::of($this->second()->toCached())->toCached()->flatten());
    }

    /**
     * @return AsyncCached<int>
     */
    #[Cache]
    public function detached(): AsyncCached
    {
        return $this->asyncCached(fn (): AsyncCached => AsyncCached::of($this->second()->value()));
    }

    /**
     * @return AsyncCached<int>
     */
    #[Cache(ttl: 90, visibility: Visibility::Shared, tags: [])]
    public function overridden(): AsyncCached
    {
        return $this->asyncCached(fn (): AsyncCached => $this->second());
    }

    /**
     * @return AsyncCached<array<int, int>>
     */
    #[Cache]
    public function collected(): AsyncCached
    {
        return $this->asyncCached(fn (): AsyncCached => AsyncCached::sequence([$this->first(), $this->second()]));
    }
}
