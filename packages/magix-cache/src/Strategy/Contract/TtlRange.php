<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use InvalidArgumentException;

/**
 * Describes one inclusive alternative for the finite constraint a strategy adds.
 *
 * Bounds are seconds relative to the origin base time. A missing bound is
 * undetermined, not a promise of unlimited retention. References are bound
 * in the enclosing Ttl or AssumeTtl declaration's argument environment.
 */
final readonly class TtlRange
{
    /**
     * Declares one interval, including a point when both bounds are equal.
     *
     * @throws InvalidArgumentException when neither bound is supplied, a bound is negative, or the bounds contradict
     */
    public function __construct(
        public int|ConstructorArg|Arg|null $min = null,
        public int|ConstructorArg|Arg|null $max = null,
    ) {
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('A lifetime range must declare at least one bound.');
        }

        if ((is_int($min) && $min < 0) || (is_int($max) && $max < 0)) {
            throw new InvalidArgumentException('A lifetime bound must be zero or greater.');
        }

        if (is_int($min) && is_int($max) && $max < $min) {
            throw new InvalidArgumentException('A lifetime range cannot bound the maximum below the minimum.');
        }
    }

    /**
     * Validates an attribute's alternatives without evaluating references.
     *
     * @param array<array-key, mixed> $alternatives Points, argument references, or inclusive TtlRange values.
     * @return non-empty-list<int|ConstructorArg|Arg|self>
     * @throws InvalidArgumentException when the alternatives are empty, not a list, or contain an invalid lifetime
     */
    public static function alternatives(array $alternatives): array
    {
        if ($alternatives === [] || !array_is_list($alternatives)) {
            throw new InvalidArgumentException('Lifetime alternatives must be a non-empty list.');
        }

        $validated = [];

        foreach ($alternatives as $alternative) {
            if (!is_int($alternative) && !$alternative instanceof ConstructorArg
                && !$alternative instanceof Arg && !$alternative instanceof self) {
                throw new InvalidArgumentException('A lifetime alternative must be integer seconds, an argument reference, or a TtlRange.');
            }

            if (is_int($alternative) && $alternative < 0) {
                throw new InvalidArgumentException('A lifetime alternative must be zero or greater.');
            }

            $validated[] = $alternative;
        }

        return $validated;
    }
}
