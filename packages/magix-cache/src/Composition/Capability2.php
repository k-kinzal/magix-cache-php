<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\Cached;

/**
 * Holds two typed cached values until they are mapped to a result.
 *
 * @template T1
 * @template T2
 */
final readonly class Capability2
{
    /**
     * @param Cached<T1> $first
     * @param Cached<T2> $second
     */
    public function __construct(
        private Cached $first,
        private Cached $second,
    ) {
    }

    /**
     * Maps both values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2): R $transform
     * @return Cached<R>
     */
    public function map(Closure $transform): Cached
    {
        return Cached::of(
            $transform($this->first->value(), $this->second->value()),
            $this->first->metadata->merge($this->second->metadata),
        );
    }
}
