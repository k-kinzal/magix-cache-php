<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use InvalidArgumentException;

use function is_finite;

use Magix\Cache\Cached;

use function max;

/**
 * Carries the final value and requested physical retention through a store.
 *
 * @template-covariant T
 */
final readonly class CacheWrite
{
    /**
     * Creates a write request; null retention uses the logical expiration.
     *
     * @param Cached<T> $cached
     * @throws InvalidArgumentException when the requested retention is invalid
     */
    public function __construct(public Cached $cached, public ?float $retainedUntil = null)
    {
        if ($retainedUntil !== null && (!is_finite($retainedUntil)
            || ($cached->metadata->expiresAt !== null && $retainedUntil < $cached->metadata->expiresAt))) {
            throw new InvalidArgumentException('Physical retention must be finite and no earlier than expiration.');
        }
    }

    /**
     * Requests longer retention without changing the value or expiration.
     *
     * @return self<T>
     */
    public function retainUntil(float $deadline): self
    {
        return new self($this->cached, max($this->retainedUntil ?? $deadline, $deadline));
    }
}
