<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\Cached;

/**
 * Holds five typed cached values until they are mapped to a result.
 *
 * @template-covariant T1
 * @template-covariant T2
 * @template-covariant T3
 * @template-covariant T4
 * @template-covariant T5
 */
final readonly class Capability5
{
    /**
     * @param Cached<T1> $first
     * @param Cached<T2> $second
     * @param Cached<T3> $third
     * @param Cached<T4> $fourth
     * @param Cached<T5> $fifth
     */
    public function __construct(
        private Cached $first,
        private Cached $second,
        private Cached $third,
        private Cached $fourth,
        private Cached $fifth,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3, T4, T5): R $transform
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
                $this->fifth->value(),
            ),
            $this->first->metadata->meet(
                $this->second->metadata,
                $this->third->metadata,
                $this->fourth->metadata,
                $this->fifth->metadata,
            ),
        );
    }
}
