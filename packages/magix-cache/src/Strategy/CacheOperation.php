<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;
use InvalidArgumentException;

use function is_finite;

use LogicException;
use Magix\Cache\Cached;

use function max;

/**
 * Carries the shared context of one execution through a strategy chain.
 *
 * The operation owns the facts every strategy must agree on: the resolved
 * key, the clock, the single base time taken right after the origin
 * succeeded, the stale candidate the lookup retained, the requested physical
 * retention, and whether the produced value may be stored. Strategies read
 * and advance this state instead of holding state of their own, so a
 * strategy instance stays reusable across boundaries and invocations.
 */
final class CacheOperation
{
    private ?float $baseTime = null;

    /** @var Cached<mixed>|null */
    private ?Cached $stale = null;

    private ?float $staleRetainedUntil = null;

    private ?float $retention = null;

    private bool $storeSuppressed = false;

    /**
     * Creates the context of one strategy chain execution.
     *
     * @param string $key Resolved storage key of the boundary.
     * @param Closure(): float $clock Source of the current Unix time.
     */
    public function __construct(
        private readonly string $key,
        private readonly Closure $clock,
    ) {
    }

    /**
     * Returns the resolved storage key of the boundary.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Returns the current Unix time with sub-second precision.
     */
    public function now(): float
    {
        return ($this->clock)();
    }

    /**
     * Reports whether the origin has succeeded during this operation.
     *
     * A strategy that adds constraints on the normal origin path checks this
     * before touching the base time, because a delegate may have answered
     * with a stale candidate instead of the origin.
     */
    public function originSucceeded(): bool
    {
        return $this->baseTime !== null;
    }

    /**
     * Returns the single base time taken right after the origin succeeded.
     *
     * Every constraint of this operation is evaluated at this one instant.
     *
     * @throws LogicException when the origin has not succeeded
     */
    public function baseTime(): float
    {
        return $this->baseTime
            ?? throw new LogicException('The base time exists only after the origin has succeeded.');
    }

    /**
     * Records the base time right after the origin succeeded.
     *
     * @internal Stamped once by the terminal strategy of the runtime.
     * @throws LogicException when a base time was already stamped
     */
    public function stampBaseTime(float $baseTime): void
    {
        if ($this->baseTime !== null) {
            throw new LogicException('The base time is taken once, right after the origin succeeds.');
        }

        $this->baseTime = $baseTime;
    }

    /**
     * Retains the expired entry the lookup found inside its retention.
     *
     * @internal Retained by the terminal strategy of the runtime.
     * @param Cached<mixed> $stale
     * @throws InvalidArgumentException when the candidate has no expiration or the retention precedes it
     */
    public function retainStale(Cached $stale, float $retainedUntil): void
    {
        $expiresAt = $stale->metadata->expiresAt
            ?? throw new InvalidArgumentException('A stale candidate must carry the expiration it was stored with.');

        if ($retainedUntil < $expiresAt) {
            throw new InvalidArgumentException('A stale candidate cannot be retained beyond its physical retention.');
        }

        $this->stale = $stale;
        $this->staleRetainedUntil = $retainedUntil;
    }

    /**
     * Returns the stale candidate the lookup retained, when one exists.
     *
     * The candidate keeps the expired expiration it was stored with.
     *
     * @return Cached<mixed>|null
     */
    public function stale(): ?Cached
    {
        return $this->stale;
    }

    /**
     * Returns the stale candidate when it may stand in right now.
     *
     * At judgement time s the candidate must satisfy expiresAt <= s,
     * s < retainedUntil, and s < expiresAt + maxAge; a candidate exactly at
     * its retention or age limit is rejected. Serving a candidate keeps its
     * expired expiration, so a parent that composes it cannot restore it
     * fresh.
     *
     * @return Cached<mixed>|null
     * @throws InvalidArgumentException when the maximum age is negative
     */
    public function staleWithin(int $maxAge): ?Cached
    {
        if ($maxAge < 0) {
            throw new InvalidArgumentException('Stale maximum age must be zero or greater.');
        }

        $expiresAt = $this->stale?->metadata->expiresAt;

        if ($expiresAt === null || $this->staleRetainedUntil === null) {
            return null;
        }

        $now = $this->now();

        if ($expiresAt <= $now && $now < $this->staleRetainedUntil && $now < $expiresAt + $maxAge) {
            return $this->stale;
        }

        return null;
    }

    /**
     * Marks the produced value as one that must not be stored.
     *
     * A served stale candidate is the canonical case: it already lives in
     * storage under its original expiration.
     */
    public function suppressStore(): void
    {
        $this->storeSuppressed = true;
    }

    /**
     * Reports whether the store stage must be skipped for this operation.
     */
    public function storeSuppressed(): bool
    {
        return $this->storeSuppressed;
    }

    /**
     * Requests that storage retains the entry at least until the given time.
     *
     * Requests only ever grow the retention, and extending retention never
     * changes the expiration itself.
     *
     * @throws InvalidArgumentException when the deadline is not finite
     */
    public function extendRetention(float $retainedUntil): void
    {
        if (!is_finite($retainedUntil)) {
            throw new InvalidArgumentException('A retention deadline must be finite.');
        }

        $this->retention = max($this->retention ?? $retainedUntil, $retainedUntil);
    }

    /**
     * Returns the latest retention deadline any strategy requested.
     */
    public function retention(): ?float
    {
        return $this->retention;
    }
}
