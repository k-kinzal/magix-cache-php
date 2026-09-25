<?php

declare(strict_types=1);

namespace Magix\Cache\Composition;

use Magix\Cache\AsyncCached;

/**
 * Typed factories for asynchronous applicative composition.
 *
 * @template-covariant T
 */
trait AsyncCombinations
{
    /**
     * Combines this value with one dependency.
     *
     * @template T2
     * @param AsyncCached<T2> $second
     * @return AsyncCapability2<T, T2>
     */
    public function combine2(AsyncCached $second): AsyncCapability2
    {
        return new AsyncCapability2($this, $second);
    }

    /**
     * Combines this value with two dependencies.
     *
     * @template T2
     * @template T3
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @return AsyncCapability3<T, T2, T3>
     */
    public function combine3(AsyncCached $second, AsyncCached $third): AsyncCapability3
    {
        return new AsyncCapability3($this, $second, $third);
    }

    /**
     * Combines this value with three dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @return AsyncCapability4<T, T2, T3, T4>
     */
    public function combine4(AsyncCached $second, AsyncCached $third, AsyncCached $fourth): AsyncCapability4
    {
        return new AsyncCapability4($this, $second, $third, $fourth);
    }

    /**
     * Combines this value with four dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @return AsyncCapability5<T, T2, T3, T4, T5>
     */
    public function combine5(AsyncCached $second, AsyncCached $third, AsyncCached $fourth, AsyncCached $fifth): AsyncCapability5
    {
        return new AsyncCapability5($this, $second, $third, $fourth, $fifth);
    }

    /**
     * Combines this value with five dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @param AsyncCached<T6> $sixth
     * @return AsyncCapability6<T, T2, T3, T4, T5, T6>
     */
    public function combine6(AsyncCached $second, AsyncCached $third, AsyncCached $fourth, AsyncCached $fifth, AsyncCached $sixth): AsyncCapability6
    {
        return new AsyncCapability6($this, $second, $third, $fourth, $fifth, $sixth);
    }

    /**
     * Combines this value with six dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @param AsyncCached<T6> $sixth
     * @param AsyncCached<T7> $seventh
     * @return AsyncCapability7<T, T2, T3, T4, T5, T6, T7>
     */
    public function combine7(AsyncCached $second, AsyncCached $third, AsyncCached $fourth, AsyncCached $fifth, AsyncCached $sixth, AsyncCached $seventh): AsyncCapability7
    {
        return new AsyncCapability7($this, $second, $third, $fourth, $fifth, $sixth, $seventh);
    }

    /**
     * Combines this value with seven dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @template T8
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @param AsyncCached<T6> $sixth
     * @param AsyncCached<T7> $seventh
     * @param AsyncCached<T8> $eighth
     * @return AsyncCapability8<T, T2, T3, T4, T5, T6, T7, T8>
     */
    public function combine8(AsyncCached $second, AsyncCached $third, AsyncCached $fourth, AsyncCached $fifth, AsyncCached $sixth, AsyncCached $seventh, AsyncCached $eighth): AsyncCapability8
    {
        return new AsyncCapability8($this, $second, $third, $fourth, $fifth, $sixth, $seventh, $eighth);
    }

    /**
     * Combines this value with eight dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @template T8
     * @template T9
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @param AsyncCached<T6> $sixth
     * @param AsyncCached<T7> $seventh
     * @param AsyncCached<T8> $eighth
     * @param AsyncCached<T9> $ninth
     * @return AsyncCapability9<T, T2, T3, T4, T5, T6, T7, T8, T9>
     */
    public function combine9(AsyncCached $second, AsyncCached $third, AsyncCached $fourth, AsyncCached $fifth, AsyncCached $sixth, AsyncCached $seventh, AsyncCached $eighth, AsyncCached $ninth): AsyncCapability9
    {
        return new AsyncCapability9($this, $second, $third, $fourth, $fifth, $sixth, $seventh, $eighth, $ninth);
    }

    /**
     * Combines this value with nine dependencies.
     *
     * @template T2
     * @template T3
     * @template T4
     * @template T5
     * @template T6
     * @template T7
     * @template T8
     * @template T9
     * @template T10
     * @param AsyncCached<T2> $second
     * @param AsyncCached<T3> $third
     * @param AsyncCached<T4> $fourth
     * @param AsyncCached<T5> $fifth
     * @param AsyncCached<T6> $sixth
     * @param AsyncCached<T7> $seventh
     * @param AsyncCached<T8> $eighth
     * @param AsyncCached<T9> $ninth
     * @param AsyncCached<T10> $tenth
     * @return AsyncCapability10<T, T2, T3, T4, T5, T6, T7, T8, T9, T10>
     */
    public function combine10(AsyncCached $second, AsyncCached $third, AsyncCached $fourth, AsyncCached $fifth, AsyncCached $sixth, AsyncCached $seventh, AsyncCached $eighth, AsyncCached $ninth, AsyncCached $tenth): AsyncCapability10
    {
        return new AsyncCapability10($this, $second, $third, $fourth, $fifth, $sixth, $seventh, $eighth, $ninth, $tenth);
    }
}
