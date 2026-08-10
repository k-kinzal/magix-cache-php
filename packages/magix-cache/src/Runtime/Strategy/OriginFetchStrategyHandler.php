<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;

/**
 * Binds one strategy to the next typed origin-fetch handler.
 *
 * @template T
 * @internal
 */
final readonly class OriginFetchStrategyHandler
{
    /** @var Closure(OriginFetch<T>): OriginFetchResult<T> */
    private Closure $next;

    /**
     * @param Closure(OriginFetch<T>): OriginFetchResult<T> $next
     */
    public function __construct(
        private CacheStrategy $strategy,
        Closure $next,
    ) {
        $this->next = $next;
    }

    /**
     * Runs the bound strategy for one origin fetch.
     *
     * @param OriginFetch<T> $operation
     * @return OriginFetchResult<T>
     */
    public function __invoke(OriginFetch $operation): OriginFetchResult
    {
        return $this->strategy->fetch($operation, $this->next);
    }
}
