<?php

declare(strict_types=1);

namespace Magix\Cache\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Reads the current wall-clock time from PHP.
 */
final readonly class SystemClock implements ClockInterface
{
    /**
     * Returns the current date and time.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
