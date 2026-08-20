<?php

declare(strict_types=1);

namespace Tests\Fixture;

use DateTimeImmutable;
use LogicException;
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
     *
     * @throws LogicException when the configured time is not a representable instant
     */
    public function now(): DateTimeImmutable
    {
        $now = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->time));

        if ($now === false) {
            throw new LogicException('The configured time is not a representable instant.');
        }

        return $now;
    }

    /**
     * Advances the configured time by a duration.
     */
    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
