<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Override;
use RuntimeException;

/**
 * A sequence of strategies acting as one strategy.
 *
 * Every operation binds the sequence in front of the received continuation,
 * so the first strategy wraps the rest and delegation order follows the
 * composition order. The result is itself a CacheStrategy, which is what
 * makes composition closed: a composed strategy composes again without
 * losing the order or the short-circuit behavior of its parts.
 */
final readonly class ComposedCacheStrategy implements CacheStrategy
{
    /**
     * Strategies in delegation order.
     *
     * @var non-empty-list<CacheStrategy>
     */
    private array $strategies;

    /**
     * Creates one strategy from a delegation sequence.
     */
    public function __construct(CacheStrategy $first, CacheStrategy ...$rest)
    {
        $this->strategies = [$first, ...array_values($rest)];
    }

    /**
     * Runs the lookup operation through the sequence.
     *
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when a delegated read fails with declared behavior
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $next->prepend(...$this->strategies)->get($operation);
    }

    /**
     * Runs the origin operation through the sequence.
     *
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws RuntimeException when the origin or a delegate fails with declared behavior
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        return $next->prepend(...$this->strategies)->fetch($operation);
    }

    /**
     * Runs the store operation through the sequence.
     *
     * @param CacheWrite<mixed> $result
     * @throws RuntimeException when a delegated write fails with declared behavior
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
        $next->prepend(...$this->strategies)->set($operation, $result);
    }
}
