<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Key;

use RuntimeException;

/**
 * Reports that a requested call cannot be turned into a cache key.
 *
 * The command line receives a boundary name and its arguments from the person
 * running it, so a boundary that cannot be loaded or a call that does not match
 * the declared parameters is bad input rather than a broken program.
 */
final class CacheKeyUnresolvable extends RuntimeException
{
}
