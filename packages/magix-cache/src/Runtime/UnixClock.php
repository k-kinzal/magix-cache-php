<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime;

use Psr\Clock\ClockInterface;

/**
 * Reads the runtime clock as an absolute Unix timestamp.
 *
 * @internal
 */
final readonly class UnixClock
{
    /**
     * Creates a Unix-time view over a PSR clock.
     */
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * Returns the current Unix time with sub-second precision.
     */
    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
