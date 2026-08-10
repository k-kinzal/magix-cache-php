<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\Cached;

/**
 * Holds four typed cached values until they are mapped to a result.
 *
 * @template T1
 * @template T2
 * @template T3
 * @template T4
 */
final readonly class Capability4
{
    /**
     * @param Cached<T1> $first
     * @param Cached<T2> $second
     * @param Cached<T3> $third
     * @param Cached<T4> $fourth
     */
    public function __construct(
        private Cached $first,
        private Cached $second,
        private Cached $third,
        private Cached $fourth,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3, T4): R $transform
     * @return Cached<R>
     */
    public function map(Closure $transform): Cached
    {
        return Cached::of(
            $transform(
                $this->first->value(),
                $this->second->value(),
                $this->third->value(),
                $this->fourth->value(),
            ),
            $this->first->metadata->merge(
                $this->second->metadata,
                $this->third->metadata,
                $this->fourth->metadata,
            ),
        );
    }
}
