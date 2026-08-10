<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
use Magix\Cache\Runtime\CacheStrategy;
use Magix\Cache\Runtime\Operation\CacheSet;

/**
 * Binds one strategy to the next typed cache-set handler.
 *
 * @template T
 * @internal
 */
final readonly class CacheSetStrategyHandler
{
    /** @var Closure(CacheSet<T>): void */
    private Closure $next;

    /**
     * @param Closure(CacheSet<T>): void $next
     */
    public function __construct(
        private CacheStrategy $strategy,
        Closure $next,
    ) {
        $this->next = $next;
    }

    /**
     * Runs the bound strategy for one cache write.
     *
     * @param CacheSet<T> $operation
     */
    public function __invoke(CacheSet $operation): void
    {
        $this->strategy->set($operation, $this->next);
    }
}
