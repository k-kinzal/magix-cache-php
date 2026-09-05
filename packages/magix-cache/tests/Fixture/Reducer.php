<?php

declare(strict_types=1);

namespace Tests\Fixture;

use InvalidArgumentException;

/**
 * Reduces integer fixtures into two cache partitions.
 */
final readonly class Reducer
{
    /**
     * Returns a stable even-or-odd partition.
     *
     * @throws InvalidArgumentException when the fixture is used with a non-integer
     */
    public static function parity(mixed $value): string
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('Parity reducer expects an integer.');
        }

        return $value % 2 === 0 ? 'even' : 'odd';
    }
}
