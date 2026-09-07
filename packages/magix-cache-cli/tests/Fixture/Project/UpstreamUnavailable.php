<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use RuntimeException;

/**
 * Represents a declared, expected upstream outage.
 */
final class UpstreamUnavailable extends RuntimeException
{
}
