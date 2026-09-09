<?php

declare(strict_types=1);

namespace Bench\AllMiss;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Keeps every written entry fresh so a miss must come from a new key.
 */
final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    /**
     * Creates one fixed instant outside the timed operation.
     */
    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    /**
     * Returns the same instant for freshness and retention judgements.
     */
    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
