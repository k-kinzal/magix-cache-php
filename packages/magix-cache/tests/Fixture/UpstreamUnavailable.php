<?php

declare(strict_types=1);

namespace Tests\Fixture;

use RuntimeException;

/**
 * Represents a declared, expected upstream outage.
 */
final class UpstreamUnavailable extends RuntimeException
{
}
