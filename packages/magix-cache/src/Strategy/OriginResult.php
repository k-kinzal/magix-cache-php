<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use InvalidArgumentException;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * Carries a successful origin value and its single expiration base time.
 *
 * @template-covariant T
 */
final readonly class OriginResult
{
    /**
     * Records the value and the time taken immediately after origin success.
     *
     * @param Cached<T> $cached
     */
    public function __construct(public Cached $cached, public float $baseTime)
    {
    }

    /**
     * Replaces metadata explicitly, keeping the value and origin base time.
     *
     * Use the metadata's with* methods to override only selected fields.
     *
     * @return self<T>
     */
    public function withMetadata(CacheMetadata $metadata): self
    {
        return new self(Cached::of($this->cached->value(), $metadata), $this->baseTime);
    }

    /**
     * Overrides the lifetime relative to the original success time.
     *
     * @return self<T>
     * @throws InvalidArgumentException when the lifetime is negative
     */
    public function withTtl(int $ttl): self
    {
        if ($ttl < 0) {
            throw new InvalidArgumentException('Cache TTL must be zero or greater.');
        }

        return $this->withMetadata($this->cached->metadata->withExpiration($this->baseTime + $ttl));
    }
}
