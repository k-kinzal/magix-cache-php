<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use function array_reverse;

use LogicException;
use RuntimeException;

/**
 * The bound remainder of a strategy chain, invoked as $next.
 *
 * A strategy receives its continuation as this type: each operation runs the
 * rest of the chain without asking the caller how the chain continues. The
 * end of a chain answers no operation, so the last strategy — the terminal
 * the runtime appends — must answer every operation itself.
 */
final readonly class NextCacheStrategy
{
    /**
     * Creates one bound link of a chain.
     *
     * @param CacheStrategy|null $strategy Strategy this link runs; null marks the end of a chain.
     * @param NextCacheStrategy|null $next Remainder behind this link.
     */
    public function __construct(
        private ?CacheStrategy $strategy,
        private ?self $next = null,
    ) {
    }

    /**
     * Returns the end of a chain, behind the last strategy.
     */
    public static function end(): self
    {
        return new self(null, null);
    }

    /**
     * Returns the given strategies bound into one chain, in order.
     */
    public static function of(CacheStrategy ...$strategies): self
    {
        return self::end()->prepend(...$strategies);
    }

    /**
     * Returns this chain with the given strategies bound in front, in order.
     */
    public function prepend(CacheStrategy ...$strategies): self
    {
        $next = $this;

        foreach (array_reverse($strategies) as $strategy) {
            $next = new self($strategy, $next);
        }

        return $next;
    }

    /**
     * Runs the lookup operation of the remaining chain.
     *
     * @return CacheRead<mixed>|null
     * @throws RuntimeException when a delegated read fails with declared behavior
     * @throws LogicException when the chain has already ended
     */
    public function get(CacheOperation $operation): ?CacheRead
    {
        return $this->strategy()->get($operation, $this->next ?? self::end());
    }

    /**
     * Runs the origin operation of the remaining chain.
     *
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     * @throws RuntimeException when the origin or a delegate fails with declared behavior
     * @throws LogicException when the chain has already ended
     */
    public function fetch(CacheOperation $operation): OriginResult|OriginFailure|CacheAnswer
    {
        return $this->strategy()->fetch($operation, $this->next ?? self::end());
    }

    /**
     * Runs the store operation of the remaining chain.
     *
     * @param CacheWrite<mixed> $result
     * @throws RuntimeException when a delegated write fails with declared behavior
     * @throws LogicException when the chain has already ended
     */
    public function set(CacheOperation $operation, CacheWrite $result): void
    {
        $this->strategy()->set($operation, $result, $this->next ?? self::end());
    }

    /**
     * Returns the strategy this link is bound to.
     *
     * @throws LogicException when the chain has already ended
     */
    public function strategy(): CacheStrategy
    {
        return $this->strategy
            ?? throw new LogicException('The last strategy of a chain must answer the operation itself instead of delegating.');
    }
}
