<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;

/**
 * Carries a successful origin value and its single constraint base time.
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
     * Adds a constraint without relaxing any dependency's metadata.
     *
     * @return self<T>
     */
    public function constrain(CacheMetadata $constraint): self
    {
        return new self(Cached::of($this->cached->value(), $this->cached->metadata->meet($constraint)), $this->baseTime);
    }
}
