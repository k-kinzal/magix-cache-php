<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
use Magix\Cache\Cache\CacheBackendFailure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Override;
use Psr\Cache\CacheException as Psr6CacheException;
use Psr\SimpleCache\CacheException as Psr16CacheException;
use Throwable;

/**
 * Treats eligible cache backend failures as misses or skipped writes.
 */
final readonly class BypassCacheErrorsStrategy extends CacheStrategyMiddleware
{
    /** @var Closure(Throwable): bool|null */
    private ?Closure $accepts;

    /**
     * @param Closure(Throwable): bool|null $accepts Optional backend-error classifier.
     */
    public function __construct(?Closure $accepts = null)
    {
        $this->accepts = $accepts;
    }

    /**
     * Reports whether a failure is one this strategy may bypass.
     *
     * A Cache implementation may come from anywhere and is under no obligation
     * to report failures as CacheBackendFailure, so the decision cannot be made
     * by the catch type alone. The bundled adapters raise CacheBackendFailure;
     * a backend used directly still reports through the PSR interfaces.
     */
    public function accepts(Throwable $error): bool
    {
        if ($this->accepts !== null) {
            return ($this->accepts)($error);
        }

        return $error instanceof CacheBackendFailure
            || $error instanceof Psr6CacheException
            || $error instanceof Psr16CacheException;
    }

    /**
     * @template T
     * @param Closure(CacheGet): (CacheEntry<T>|null) $next
     * @return CacheEntry<T>|null
     * @throws Throwable when the failure is not one this strategy bypasses
     */
    #[Override]
    public function get(CacheGet $operation, Closure $next): ?CacheEntry
    {
        try {
            return $next($operation);
        } catch (Throwable $error) {
            if (!$this->accepts($error)) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * @template T
     * @param CacheSet<T> $operation
     * @param Closure(CacheSet<T>): void $next
     * @throws Throwable when the failure is not one this strategy bypasses
     */
    #[Override]
    public function set(CacheSet $operation, Closure $next): void
    {
        try {
            $next($operation);
        } catch (Throwable $error) {
            if (!$this->accepts($error)) {
                throw $error;
            }
        }
    }
}
