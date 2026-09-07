<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;
use InvalidArgumentException;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\Contract\Ttl;
use Override;
use RuntimeException;

/**
 * Serves a retained expired entry when a delegate fails with accepted behavior.
 *
 * The capture range is exactly the delegated fetch, and the capture takes
 * only the RuntimeException family — failures the origin declares as
 * behavior. Bugs never enter the catch and can never be answered with stale
 * data; an origin that meets an expected outage in a foreign exception
 * hierarchy translates it into its own declared RuntimeException subtype at
 * the boundary. A served candidate keeps its expired expiration and is never
 * stored again, and extending the physical retention on store never changes
 * the expiration itself.
 */
final readonly class StaleIfErrorCacheStrategy implements CacheStrategy
{
    /**
     * Creates a stale-if-error strategy.
     *
     * @param int $maxAge Maximum seconds past expiration a retained value may still stand in.
     * @param Closure(RuntimeException): bool $accepts Judges whether a declared failure is eligible.
     * @throws InvalidArgumentException when the maximum age is negative
     */
    public function __construct(
        private int $maxAge,
        private Closure $accepts,
    ) {
        if ($maxAge < 0) {
            throw new InvalidArgumentException('Stale maximum age must be zero or greater.');
        }
    }

    /**
     * Delegates the lookup unchanged.
     *
     * @return Cached<mixed>|null
     * @throws RuntimeException when the delegated read fails with declared behavior
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        return $next->get($operation);
    }

    /**
     * Serves the stale candidate when the delegate fails acceptably.
     *
     * @return Cached<mixed>
     * @throws RuntimeException when the failure is not accepted or no eligible candidate exists
     */
    #[Override]
    #[Ttl(unconstrained: true)]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        try {
            return $next->fetch($operation);
        } catch (RuntimeException $error) {
            if (($this->accepts)($error) !== true) {
                throw $error;
            }

            $served = $operation->staleWithin($this->maxAge);

            if ($served === null) {
                throw $error;
            }

            $operation->suppressStore();

            return $served;
        }
    }

    /**
     * Extends the physical retention so a future failure can be answered.
     *
     * @param Cached<mixed> $result
     * @throws RuntimeException when the delegated write fails with declared behavior
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
        $expiresAt = $result->metadata->expiresAt;

        if ($expiresAt !== null) {
            $operation->extendRetention($expiresAt + $this->maxAge);
        }

        $next->set($operation, $result);
    }
}
