<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use RuntimeException;

/**
 * Carries declared behavior raised by the origin, preserving its identity.
 *
 * Only the origin call is captured into this result. A strategy may answer
 * it; otherwise the runtime rethrows the original exception. Failures from
 * other stages and bugs never become an origin failure.
 */
final readonly class OriginFailure
{
    /**
     * Records the exception raised by the origin.
     */
    public function __construct(public RuntimeException $error)
    {
    }
}
