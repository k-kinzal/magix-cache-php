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
     * Creates a stale-if-error strategy.
     *
     * @param Closure(Throwable): bool|null $accepts Optional error classifier; exceptions are accepted by default.
     * @throws InvalidArgumentException when the maximum stale age is negative
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
     * The origin is arbitrary caller code, so this strategy cannot know which
     * exception hierarchy a failure arrives in: a PSR-18 client, Doctrine DBAL,
     * and a bare JsonException all sit outside RuntimeException. It therefore
     * intercepts every origin failure and delegates the decision to the
     * classifier, which by default accepts Exception and leaves Error alone.
     *
     * @template T
     * @param OriginFetch<T> $operation
     * @param Closure(OriginFetch<T>): OriginFetchResult<T> $next
     * @return OriginFetchResult<T>
     * @throws Throwable when the failure is not eligible, or no retained entry may stand in for it
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
