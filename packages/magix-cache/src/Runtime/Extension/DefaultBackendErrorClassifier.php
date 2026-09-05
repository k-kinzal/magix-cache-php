<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

use Magix\Cache\Cache\CacheBackendFailure;
use Override;
use Psr\Cache\CacheException as Psr6CacheException;
use Psr\SimpleCache\CacheException as Psr16CacheException;
use RuntimeException;

/**
 * Accepts the failures the bundled adapters and the PSR interfaces declare.
 *
 * The Cache port declares CacheBackendFailure, which is what the bundled
 * adapters raise. A hand-written adapter may report through the PSR cache
 * exception interfaces instead; those are accepted when they arrive in the
 * declared RuntimeException family.
 */
final readonly class DefaultBackendErrorClassifier implements BackendErrorClassifier
{
    /**
     * Reports whether the failure is a declared cache backend fault.
     */
    #[Override]
    public function isBackendFailure(RuntimeException $error, CacheAccess $access): bool
    {
        unset($access);

        return $error instanceof CacheBackendFailure
            || $error instanceof Psr6CacheException
            || $error instanceof Psr16CacheException;
    }
}
