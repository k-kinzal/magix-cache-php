<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Closure;
use Magix\Cache\AsyncCached;

/**
 * Holds two typed cached values until they are mapped to a result.
 *
 * @template-covariant T1
 * @template-covariant T2
 */
final readonly class AsyncCapability2
{
    /**
     * @param AsyncCached<T1> $first
     * @param AsyncCached<T2> $second
     */
    public function __construct(
        private AsyncCached $first,
        private AsyncCached $second,
    ) {
    }

    /**
     * Maps both values and attaches their composed metadata.
     *
     * @template R
     * @param Closure(T1, T2): R $transform
     * @return AsyncCached<R>
     */
    public function map(Closure $transform): AsyncCached
    {
        return AsyncCached::sequence([$this->first, $this->second])->map(static function (array $values) use ($transform): mixed {
            /** @var array{T1, T2} $values */
            return $transform(...$values);
        });
    }
}
