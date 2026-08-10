<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\Operation\CacheGet;

/**
 * Binds one strategy to the next typed cache-get handler.
 *
 * @template T
 * @internal
 */
final readonly class CacheGetStrategyHandler
{
    /** @var Closure(CacheGet): (CacheEntry<T>|null) */
    private Closure $next;

    /**
     * @param Closure(CacheGet): (CacheEntry<T>|null) $next
     */
    public function __construct(
        private CacheStrategy $strategy,
        Closure $next,
    ) {
        $this->next = $next;
    }

    /**
     * Runs the bound strategy for one cache read.
     *
     * @return CacheEntry<T>|null
     */
    public function __invoke(CacheGet $operation): ?CacheEntry
    {
        return $this->strategy->get($operation, $this->next);
    }
}
