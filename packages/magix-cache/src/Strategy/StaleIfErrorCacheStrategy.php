<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;
use InvalidArgumentException;

use function is_a;

use Magix\Cache\Cached;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Observation\CacheEvent;
use Magix\Cache\Observation\CacheObserver;
use Override;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Owns the retained candidate and fallback decisions of one execution.
 *
 * Only declared delegated failures are eligible. Candidate retention and age
 * are judged at the failure time. An answer preserves its expired metadata,
 * and a store extends physical retention without extending freshness.
 */
final class StaleIfErrorCacheStrategy implements CacheStrategy
{
    /** @var CacheRead<mixed>|null */
    private ?CacheRead $candidate = null;

    /** @var Cached<mixed>|null The retained result selected by this strategy. */
    private ?Cached $served = null;

    /**
     * Creates one execution's stale-if-error behavior.
     *
     * @param list<string> $exceptions RuntimeException subtypes eligible for fallback.
     * @throws InvalidArgumentException when age or accepted exception types are invalid
     */
    public function __construct(
        private readonly int $maxAge,
        private readonly array $exceptions,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?CacheObserver $observer = null,
    ) {
        if ($maxAge < 0 || $exceptions === []) {
            throw new InvalidArgumentException('StaleIfError requires a non-negative maximum age and accepted exception types.');
        }

        foreach ($exceptions as $type) {
            if (!is_a($type, RuntimeException::class, true)) {
                throw new InvalidArgumentException('StaleIfError accepts only RuntimeException subtypes.');
            }
        }
    }

    /**
     * Retains a lookup candidate in this strategy's own execution state.
     *
     * Delegated failures propagate unchanged.
     *
     * @return CacheRead<mixed>|null
     * @param Closure(string): (CacheRead<mixed>|null) $next
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        $read = $next($key);
        $this->candidate = $read;

        return $read;
    }

    /**
     * Handles an eligible failure from the delegated inquiry with retained data.
     *
     * @return Cached<mixed>
     * @throws RuntimeException when a delegate fails
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    public function fetch(string $key, Closure $next): Cached
    {
        try {
            return $next();
        } catch (RuntimeException $error) {
            $accepted = false;

            foreach ($this->exceptions as $type) {
                $accepted = $accepted || is_a($error, $type);
            }

            if (!$accepted) {
                throw $error;
            }

            $candidate = $this->candidate;
            $expiresAt = $candidate?->cached->metadata->expiresAt;
            $now = (float) $this->clock->now()->format('U.u');

            if ($candidate === null || $expiresAt === null || $now < $expiresAt
                || $now >= $candidate->retainedUntil || $now >= $expiresAt + $this->maxAge) {
                throw $error;
            }

            $this->served = $candidate->cached;

            return $this->served;
        }
    }

    /**
     * Reports and suppresses a write of the served candidate, otherwise extends retention.
     *
     * Delegated failures propagate unchanged.
     *
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $request, Closure $next): void
    {
        if ($request->cached === $this->served) {
            $this->observer?->observe(CacheEvent::StaleServed, $key);

            return;
        }

        $expiresAt = $request->cached->metadata->expiresAt;

        if ($expiresAt !== null) {
            $request = $request->retainUntil($expiresAt + $this->maxAge);
        }

        $next($key, $request);
    }

}
