<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;
use Magix\Cache\Cached;
use Override;

/**
 * Composes middleware by nesting ordinary operation closures.
 *
 * The first strategy is outermost. Compositions nest with the same interface;
 * an empty composition calls next unchanged for each operation.
 */
final readonly class ComposedCacheStrategy implements CacheStrategy
{
    /** @var list<CacheStrategy> */
    private array $strategies;

    /**
     * Creates a composition in delegation order, including the empty identity.
     */
    public function __construct(CacheStrategy ...$strategies)
    {
        $this->strategies = array_values($strategies);
    }

    /**
     * Wraps a read in the composed middleware.
     *
     * Delegated failures propagate unchanged.
     *
     * @param Closure(string): (CacheRead<mixed>|null) $next
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        foreach (array_reverse($this->strategies) as $strategy) {
            $next = static fn (string $key): ?CacheRead => $strategy->get($key, $next);
        }

        return $next($key);
    }

    /**
     * Wraps an argument-free inquiry in the composed middleware.
     *
     * Delegated failures propagate unchanged.
     *
     * @param Closure(): Cached<mixed> $next
     * @return Cached<mixed>
     */
    #[Override]
    public function fetch(string $key, Closure $next): Cached
    {
        foreach (array_reverse($this->strategies) as $strategy) {
            $next = static fn (): Cached => $strategy->fetch($key, $next);
        }

        return $next();
    }

    /**
     * Wraps a write in the composed middleware.
     *
     * Delegated failures propagate unchanged.
     *
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $request, Closure $next): void
    {
        foreach (array_reverse($this->strategies) as $strategy) {
            $next = static function (string $key, CacheWrite $request) use ($strategy, $next): void {
                $strategy->set($key, $request, $next);
            };
        }

        $next($key, $request);
    }
}
