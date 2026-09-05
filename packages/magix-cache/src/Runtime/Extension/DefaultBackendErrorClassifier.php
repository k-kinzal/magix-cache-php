<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Extension;

use Magix\Cache\Cache\CacheBackendFailure;
use Override;
use Psr\Cache\CacheException as Psr6CacheException;
use Psr\SimpleCache\CacheException as Psr16CacheException;
use Throwable;

/**
 * Accepts the failures the bundled adapters and the PSR interfaces declare.
 *
 * A Cache implementation may come from anywhere and is under no obligation to
 * report failures as CacheBackendFailure, so the decision cannot be made by a
 * catch type alone. The bundled adapters raise CacheBackendFailure; a backend
 * used directly still reports through the PSR cache exception interfaces.
 */
final readonly class DefaultBackendErrorClassifier implements BackendErrorClassifier
{
    /**
     * Reports whether the failure is a declared cache backend fault.
     */
    #[Override]
    public function isBackendFailure(Throwable $error, CacheAccess $access): bool
    {
        unset($access);

        return $error instanceof CacheBackendFailure
            || $error instanceof Psr6CacheException
            || $error instanceof Psr16CacheException;
    }
}
