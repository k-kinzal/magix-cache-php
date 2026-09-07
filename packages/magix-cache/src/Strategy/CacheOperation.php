<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy;

use Closure;

/**
 * Supplies the key and clock of one execution to its strategies.
 *
 * Each execution owns a fresh strategy composition. Feature state belongs
 * to those strategies; stage results and storage requests travel explicitly
 * through the chain. This context has no mutable execution or feature state.
 */
final readonly class CacheOperation
{
    /**
     * Creates one operation's shared inputs.
     *
     * @param Closure(): float $clock Source of the current Unix time.
     */
    public function __construct(private string $key, private Closure $clock)
    {
    }

    /**
     * Returns the resolved storage key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Reads the current Unix time with sub-second precision.
     */
    public function now(): float
    {
        return ($this->clock)();
    }
}
