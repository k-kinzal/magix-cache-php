<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\Cached;

/**
 * Holds three typed cached values until they are mapped to a result.
 *
 * @template T1
 * @template T2
 * @template T3
 */
final readonly class Capability3
{
    /**
     * @param Cached<T1> $first
     * @param Cached<T2> $second
     * @param Cached<T3> $third
     */
    public function __construct(
        private Cached $first,
        private Cached $second,
        private Cached $third,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3): R $transform
     * @return Cached<R>
     */
    public function map(Closure $transform): Cached
    {
        return Cached::of(
            $transform($this->first->value(), $this->second->value(), $this->third->value()),
            $this->first->metadata->merge($this->second->metadata, $this->third->metadata),
        );
    }
}
