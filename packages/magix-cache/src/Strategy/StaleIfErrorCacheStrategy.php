<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use InvalidArgumentException;

use function is_a;

use Magix\Cache\Strategy\Contract\Ttl;
use Override;
use RuntimeException;

/**
 * Owns the retained candidate and fallback decisions of one execution.
 *
 * Only declared origin failures are eligible. Candidate retention and age
 * are judged at the failure time. An answer preserves its expired metadata,
 * and a store extends physical retention without extending freshness.
 */
final class StaleIfErrorCacheStrategy implements CacheStrategy
{
    /** @var CacheRead<mixed>|null */
    private ?CacheRead $candidate = null;

    /**
     * Creates one execution's stale-if-error behavior.
     *
     * @param list<string> $exceptions RuntimeException subtypes eligible for fallback.
     * @throws InvalidArgumentException when age or accepted exception types are invalid
     */
    public function __construct(private readonly int $maxAge, private readonly array $exceptions)
    {
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
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when the delegated read fails
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        $read = $next->get($operation);
        $this->candidate = $read;

        return $read;
    }

    /**
     * Answers an accepted origin failure with a currently eligible candidate.
     *
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws RuntimeException when a delegate raises a failure outside the origin
     */
    #[Override]
    #[Ttl(unconstrained: true)]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        $result = $next->fetch($operation);

        if (!$result instanceof OriginFailure) {
            return $result;
        }

        $accepted = false;

        foreach ($this->exceptions as $type) {
            $accepted = $accepted || is_a($result->error, $type);
        }

        if (!$accepted) {
            return $result;
        }

        $candidate = $this->candidate;
        $expiresAt = $candidate?->cached->metadata->expiresAt;
        $now = $operation->now();

        if ($candidate === null || $expiresAt === null || $now < $expiresAt
            || $now >= $candidate->retainedUntil || $now >= $expiresAt + $this->maxAge) {
            return $result;
        }

        return new CacheAnswer($candidate->cached, event: 'StaleServed');
    }

    /**
     * Extends this write's retention to cover the configured fallback window.
     *
     * @param CacheWrite<mixed> $request
     * @throws RuntimeException when the delegated write fails
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $request, NextCacheStrategy $next): void
    {
        $expiresAt = $request->cached->metadata->expiresAt;

        if ($expiresAt !== null) {
            $request = $request->retainUntil($expiresAt + $this->maxAge);
        }

        $next->set($operation, $request);
    }

}
