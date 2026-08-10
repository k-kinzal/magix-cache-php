<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
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
     * @template T
     * @param Closure(CacheGet): (CacheEntry<T>|null) $next
     * @return CacheEntry<T>|null
     */
    #[Override]
    public function get(CacheGet $operation, Closure $next): ?CacheEntry
    {
        try {
            return $next($operation);
        } catch (Throwable $error) {
            $accepted = $this->accepts !== null
                ? ($this->accepts)($error)
                : $error instanceof Psr6CacheException || $error instanceof Psr16CacheException;

            if (!$accepted) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * @template T
     * @param CacheSet<T> $operation
     * @param Closure(CacheSet<T>): void $next
     */
    #[Override]
    public function set(CacheSet $operation, Closure $next): void
    {
        try {
            $next($operation);
        } catch (Throwable $error) {
            $accepted = $this->accepts !== null
                ? ($this->accepts)($error)
                : $error instanceof Psr6CacheException || $error instanceof Psr16CacheException;

            if (!$accepted) {
                throw $error;
            }
        }
    }
}
