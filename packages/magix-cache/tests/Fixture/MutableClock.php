<?php

declare(strict_types=1);

namespace Tests\Fixture;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * Supplies deterministic time to unit tests.
 */
final class MutableClock implements ClockInterface
{
    /**
     * Creates a clock at the supplied Unix time.
     */
    public function __construct(public float $time)
    {
    }

    /**
     * Returns the configured Unix time.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.sprintf('%.6F', $this->time));
    }

    /**
     * Advances the configured time by a duration.
     */
    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
