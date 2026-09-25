<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\AsyncCached;

/**
 * Holds three typed cached values until they are mapped to a result.
 *
 * @template-covariant T1
 * @template-covariant T2
 * @template-covariant T3
 */
final readonly class AsyncCapability3
{
    /**
     * @param AsyncCached<T1> $first
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     */
    public function __construct(
        private AsyncCached $first,
        private AsyncCached $second,
        private AsyncCached $third,
    ) {
    }

    /**
     * Maps all values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2, T3): R $transform
     * @return AsyncCached<R>
     */
    public function map(Closure $transform): AsyncCached
    {
        return AsyncCached::sequence([$this->first, $this->second, $this->third])->map(static function (array $values) use ($transform): mixed {
            /** @var array{T1, T2, T3} $values */
            return $transform(...$values);
        });
    }
}
