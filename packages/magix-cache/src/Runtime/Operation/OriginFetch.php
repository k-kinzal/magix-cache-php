<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Operation;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Cached;
use Psr\Clock\ClockInterface;

/**
 * Describes one origin computation and its optional stale candidate.
 *
 * @template-covariant T
 */
final readonly class OriginFetch
{
    /**
     * @param Closure(): Cached<T> $origin
     * @param CacheEntry<T>|null $stale
     */
    public function __construct(
        public string $key,
        private Closure $origin,
        private ?CacheEntry $stale,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Invokes the origin computation.
     *
     * @return Cached<covariant T>
     */
    public function invoke(): Cached
    {
        return ($this->origin)();
    }

    /**
     * Returns this origin computation with a transformed operation key.
     *
     * @return self<T>
     */
    public function withKey(string $key): self
    {
        return new self($key, $this->origin, $this->stale, $this->clock);
    }

    /**
     * Returns the stale candidate retained by the cache, when present.
     *
     * @return CacheEntry<T>|null
     */
    public function stale(): ?CacheEntry
    {
        return $this->stale;
    }

    /**
     * Returns the current Unix time from the runtime clock.
     */
    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
