<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use Attribute;
use InvalidArgumentException;

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
     * Assumed lower bound of the named range; null for alternatives or no constraint.
     */
    public int|ConstructorArg|Arg|null $min;

    /**
     * Assumed upper bound of the named range; null for alternatives or no constraint.
     */
    public int|ConstructorArg|Arg|null $max;

    /**
     * Whether only the child was named, assuming that it adds no TTL constraint.
     */
    public bool $unconstrained;

    /**
     * @var non-empty-list<int|ConstructorArg|Arg|TtlRange>|null
     */
    public ?array $oneOf;

    /**
     * Declares the assumed lifetime contract of one composed strategy.
     *
     * @param string $strategy Class name of the composed strategy the assumption covers.
     * @param int|Arg|TtlRange|null ...$lifetimes Positional fixed lifetimes, Arg references or ranges; alternatively named min/max bounds in seconds. Null is allowed only for an omitted named bound. No lifetimes assumes that the named child adds no TTL constraint.
     * @throws InvalidArgumentException when the strategy reference is empty, bounds or alternatives are invalid, an unknown name is supplied, or named bounds and positional alternatives are mixed
     */
    public function __construct(
        public string $strategy,
        int|Arg|TtlRange|null ...$lifetimes,
    ) {
        if ($strategy === '') {
            throw new InvalidArgumentException('An assumption must name the strategy class it covers.');
        }

        $contract = new Ttl(...$lifetimes);
        $this->min = $contract->min;
        $this->max = $contract->max;
        $this->unconstrained = $contract->unconstrained;
        $this->oneOf = $contract->oneOf;
    }
}
