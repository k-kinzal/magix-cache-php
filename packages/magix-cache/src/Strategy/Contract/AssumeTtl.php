<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use Attribute;
use InvalidArgumentException;

use function is_int;

/**
 * Declares an explicit analysis assumption for one composed strategy.
 *
 * The declaration replaces exactly one analysis item — the lifetime contract
 * of the named child strategy — when its construction or contract cannot be
 * read statically, such as a strategy built by an external factory. It never
 * changes what runs, never corrects a runtime value into the declared range,
 * and never marks effects it does not mention as analyzed. Reusable behavior
 * belongs in the contract of the strategy itself; this attribute only feeds
 * the analysis of the composition it is declared on.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AssumeTtl
{
    /**
     * Declares the assumed lifetime contract of one composed strategy.
     *
     * @param string $strategy Class name of the composed strategy the assumption covers.
     * @param int|Arg|null $min Assumed lower bound in seconds of the added constraint.
     * @param int|Arg|null $max Assumed upper bound in seconds of the added constraint.
     * @param bool $unconstrained Assumes that no lifetime constraint is added.
     * @throws InvalidArgumentException when the strategy reference is empty, a bound is negative, the bounds contradict, or the declaration says nothing
     */
    public function __construct(
        public string $strategy,
        public int|Arg|null $min = null,
        public int|Arg|null $max = null,
        public bool $unconstrained = false,
    ) {
        if ($strategy === '') {
            throw new InvalidArgumentException('An assumption must name the strategy class it covers.');
        }

        if ($unconstrained && ($min !== null || $max !== null)) {
            throw new InvalidArgumentException('An unconstrained lifetime assumption cannot also declare bounds.');
        }

        if (!$unconstrained && $min === null && $max === null) {
            throw new InvalidArgumentException('A lifetime assumption must declare a bound, or unconstrained: true.');
        }

        if ((is_int($min) && $min < 0) || (is_int($max) && $max < 0)) {
            throw new InvalidArgumentException('A lifetime bound must be zero or greater.');
        }

        if (is_int($min) && is_int($max) && $max < $min) {
            throw new InvalidArgumentException('A lifetime assumption cannot bound the maximum below the minimum.');
        }
    }
}
