<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\AsyncCached;

/**
 * Holds four typed cached values until they are mapped to a result.
 *
 * @template-covariant T1
 * @template-covariant T2
 * @template-covariant T3
 * @template-covariant T4
 */
final readonly class AsyncCapability4
{
    /**
     * @param AsyncCached<T1> $first
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     */
    public function __construct(
        private AsyncCached $first,
        private AsyncCached $second,
        private AsyncCached $third,
        private AsyncCached $fourth,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3, T4): R $transform
     * @return AsyncCached<R>
     */
    public function map(Closure $transform): AsyncCached
    {
        return AsyncCached::sequence([$this->first, $this->second, $this->third, $this->fourth])->map(static function (array $values) use ($transform): mixed {
            /** @var array{T1, T2, T3, T4} $values */
            return $transform(...$values);
        });
    }
}
