<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\AsyncCached;

/**
 * Holds eight typed cached values until they are mapped to a result.
 *
 * @template-covariant T1
 * @template-covariant T2
 * @template-covariant T3
 * @template-covariant T4
 * @template-covariant T5
 * @template-covariant T6
 * @template-covariant T7
 * @template-covariant T8
 */
final readonly class AsyncCapability8
{
    /**
     * @param AsyncCached<T1> $first
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @param AsyncCached<T6> $sixth
     * @param AsyncCached<T7> $seventh
     * @param AsyncCached<T8> $eighth
     */
    public function __construct(
        private AsyncCached $first,
        private AsyncCached $second,
        private AsyncCached $third,
        private AsyncCached $fourth,
        private AsyncCached $fifth,
        private AsyncCached $sixth,
        private AsyncCached $seventh,
        private AsyncCached $eighth,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3, T4, T5, T6, T7, T8): R $transform
     * @return AsyncCached<R>
     */
    public function map(Closure $transform): AsyncCached
    {
        return AsyncCached::sequence([$this->first, $this->second, $this->third, $this->fourth, $this->fifth, $this->sixth, $this->seventh, $this->eighth])->map(static function (array $values) use ($transform): mixed {
            /** @var array{T1, T2, T3, T4, T5, T6, T7, T8} $values */
            return $transform(...$values);
        });
    }
}
