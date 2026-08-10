<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use function array_reverse;
use function array_values;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;

/**
 * Composes strategies in HTTP-middleware order from outermost to innermost.
 */
final readonly class CompositeCacheStrategy implements CacheStrategy
{
    /** @var list<CacheStrategy> */
    private array $strategies;

    /**
     * Creates a strategy from an ordered middleware list.
     *
     */
    public function __construct(CacheStrategy ...$strategies)
    {
        $this->strategies = array_values($strategies);
    }

    /**
     * @template T
     * @param Closure(CacheGet): (CacheEntry<T>|null) $next
     * @return CacheEntry<T>|null
     */
    public function get(CacheGet $operation, Closure $next): ?CacheEntry
    {
        $handler = $next;

        foreach (array_reverse($this->strategies) as $strategy) {
            $handler = (new CacheGetStrategyHandler($strategy, $handler))(...);
        }

        return $handler($operation);
    }

    /**
     * @template T
     * @param OriginFetch<T> $operation
     * @param Closure(OriginFetch<T>): OriginFetchResult<T> $next
     * @return OriginFetchResult<T>
     */
    public function fetch(OriginFetch $operation, Closure $next): OriginFetchResult
    {
        $handler = $next;

        foreach (array_reverse($this->strategies) as $strategy) {
            $handler = (new OriginFetchStrategyHandler($strategy, $handler))(...);
        }

        return $handler($operation);
    }

    /**
     * @template T
     * @param CacheSet<T> $operation
     * @param Closure(CacheSet<T>): void $next
     */
    public function set(CacheSet $operation, Closure $next): void
    {
        $handler = $next;

        foreach (array_reverse($this->strategies) as $strategy) {
            $handler = (new CacheSetStrategyHandler($strategy, $handler))(...);
        }

        $handler($operation);
    }
}
