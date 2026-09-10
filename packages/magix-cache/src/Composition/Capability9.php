<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\Cached;

/**
 * Holds nine typed cached values until they are mapped to a result.
 *
 * @template-covariant T1
 * @template-covariant T2
 * @template-covariant T3
 * @template-covariant T4
 * @template-covariant T5
 * @template-covariant T6
 * @template-covariant T7
 * @template-covariant T8
 * @template-covariant T9
 */
final readonly class Capability9
{
    /**
     * @param Cached<T1> $first
     * @param Cached<T2> $second
     * @param Cached<T3> $third
     * @param Cached<T4> $fourth
     * @param Cached<T5> $fifth
     * @param Cached<T6> $sixth
     * @param Cached<T7> $seventh
     * @param Cached<T8> $eighth
     * @param Cached<T9> $ninth
     */
    public function __construct(
        private Cached $first,
        private Cached $second,
        private Cached $third,
        private Cached $fourth,
        private Cached $fifth,
        private Cached $sixth,
        private Cached $seventh,
        private Cached $eighth,
        private Cached $ninth,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3, T4, T5, T6, T7, T8, T9): R $transform
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
                $this->sixth->value(),
                $this->seventh->value(),
                $this->eighth->value(),
                $this->ninth->value(),
            ),
            $this->first->metadata->meet(
                $this->second->metadata,
                $this->third->metadata,
                $this->fourth->metadata,
                $this->fifth->metadata,
                $this->sixth->metadata,
                $this->seventh->metadata,
                $this->eighth->metadata,
                $this->ninth->metadata,
            ),
        );
    }
}
