<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use Attribute;
use InvalidArgumentException;

use function is_int;

/**
 * Declares the lifetime constraint a strategy adds on the normal origin path.
 *
 * The contract is about the candidate constraint the operation contributes,
 * not about the format of an algorithm: a declaration with bounds promises
 * that the strategy meets one finite lifetime constraint into the produced
 * metadata, that the constraint lies within the declared bounds relative to
 * the base time, and that the strategy never extends an expiration a
 * dependency already imposed. A missing bound is undetermined, not
 * unlimited. Declaring unconstrained promises that the operation adds no
 * lifetime constraint at all.
 *
 * The declaration covers the normal origin path only; a stale answer keeps
 * the expired expiration it was stored with.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Ttl
{
    /**
     * Declares the candidate lifetime contract of one operation.
     *
     * @param int|ConstructorArg|Arg|null $min Lower bound in seconds of the added constraint.
     * @param int|ConstructorArg|Arg|null $max Upper bound in seconds of the added constraint.
     * @param bool $unconstrained Declares that no lifetime constraint is added.
     * @throws InvalidArgumentException when a bound is negative, the bounds contradict, or the declaration says nothing
     */
    public function __construct(
        public int|ConstructorArg|Arg|null $min = null,
        public int|ConstructorArg|Arg|null $max = null,
        public bool $unconstrained = false,
    ) {
        if ($unconstrained && ($min !== null || $max !== null)) {
            throw new InvalidArgumentException('An unconstrained lifetime contract cannot also declare bounds.');
        }

        if (!$unconstrained && $min === null && $max === null) {
            throw new InvalidArgumentException('A lifetime contract must declare a bound, or unconstrained: true.');
        }

        if ((is_int($min) && $min < 0) || (is_int($max) && $max < 0)) {
            throw new InvalidArgumentException('A lifetime bound must be zero or greater.');
        }

        if (is_int($min) && is_int($max) && $max < $min) {
            throw new InvalidArgumentException('A lifetime contract cannot bound the maximum below the minimum.');
        }
    }
}
