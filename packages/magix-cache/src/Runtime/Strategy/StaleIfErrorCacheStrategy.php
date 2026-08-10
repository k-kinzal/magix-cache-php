<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
use Exception;
use InvalidArgumentException;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;

use function max;

use Override;
use Throwable;

/**
 * Retains expired entries and serves them when an eligible origin fetch fails.
 */
final readonly class StaleIfErrorCacheStrategy extends CacheStrategyMiddleware
{
    /** @var Closure(Throwable): bool|null */
    private ?Closure $accepts;

    /**
     * @param Closure(Throwable): bool|null $accepts Optional error classifier; exceptions are accepted by default.
     */
    public function __construct(
        public int $maxAge,
        ?Closure $accepts = null,
    ) {
        if ($maxAge < 0) {
            throw new InvalidArgumentException('Stale maximum age must be zero or greater.');
        }

        $this->accepts = $accepts;
    }

    /**
     * @template T
     * @param OriginFetch<T> $operation
     * @param Closure(OriginFetch<T>): OriginFetchResult<T> $next
     * @return OriginFetchResult<T>
     */
    #[Override]
    public function fetch(OriginFetch $operation, Closure $next): OriginFetchResult
    {
        try {
            return $next($operation);
        } catch (Throwable $error) {
            $accepted = $this->accepts !== null
                ? ($this->accepts)($error)
                : $error instanceof Exception;

            if (!$accepted) {
                throw $error;
            }

            $stale = $operation->stale();
            $now = $operation->now();

            if (
                $stale === null
                || $stale->expiresAt > $now
                || $stale->retainedUntil <= $now
                || $stale->expiresAt + $this->maxAge <= $now
            ) {
                throw $error;
            }

            return new OriginFetchResult($stale);
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
        $entry = $operation->entry();
        $retainedUntil = max($entry->retainedUntil, $entry->expiresAt + $this->maxAge);

        $next($operation->withEntry($entry->withRetainedUntil($retainedUntil)));
    }
}
