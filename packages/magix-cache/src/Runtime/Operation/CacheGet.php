<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Operation;

use Psr\Clock\ClockInterface;

/**
 * Describes one cache read as it passes through a strategy chain.
 */
final readonly class CacheGet
{
    /**
     * Creates a read for one storage key and runtime clock.
     */
    public function __construct(
        public string $key,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Returns the current Unix time from the runtime clock.
     */
    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    /**
     * Returns this read with a transformed storage key.
     *
     */
    public function withKey(string $key): self
    {
        return new self($key, $this->clock);
    }
}
