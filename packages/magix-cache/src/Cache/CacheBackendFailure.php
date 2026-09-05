<?php

declare(strict_types=1);

namespace Magix\Cache\Cache;

use RuntimeException;

/**
 * Reports that the storage backend behind a Magix cache could not serve an operation.
 *
 * This is the only failure the Cache port declares. Adapters translate the
 * backend-specific failures they meet into this type, so a strategy can decide
 * whether to bypass the cache without catching unrelated programmer errors.
 */
final class CacheBackendFailure extends RuntimeException
{
}
