<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use Attribute;
use InvalidArgumentException;

/**
 * Declares the lifetime constraint a strategy adds on the normal origin path.
 *
 * The contract is about the candidate constraint the operation contributes,
 * not about the format of an algorithm: a declaration with bounds promises
 * that the strategy meets one finite lifetime constraint into the produced
 * metadata, that the constraint lies within the declared bounds relative to
 * the base time, and that the strategy never extends an expiration a
 * dependency already imposed. A missing bound is undetermined, not
 * unlimited. Omitting the attribute, or supplying no arguments, declares
 * that the operation adds no lifetime constraint at all.
 * With positional alternatives, the constraint belongs to the union of the
 * declared points and ranges. Alternatives describe possible outcomes, not simultaneous
 * constraints, and do not prove which runtime condition selects each one.
 *
 * The declaration covers the normal origin path only; a stale answer keeps
 * the expired expiration it was stored with.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Ttl
{
    /**
     * Lower bound of the named range; null for alternatives or no constraint.
     */
    public int|ConstructorArg|Arg|null $min;

    /**
     * Upper bound of the named range; null for alternatives or no constraint.
     */
    public int|ConstructorArg|Arg|null $max;

    /**
     * Whether no lifetime arguments were supplied and no constraint is added.
     */
    public bool $unconstrained;

    /**
     * Normalized positional alternatives; null for named bounds or no constraint.
     *
     * @var non-empty-list<int|ConstructorArg|Arg|TtlRange>|null
     */
    public ?array $oneOf;

    /**
     * Declares the candidate lifetime contract of one operation.
     *
     * Ttl(30) declares a fixed constraint; Ttl(30, 60, new TtlRange(600, 900))
     * declares alternatives. Ttl(min: 600, max: 900) is a single range.
     * Named bounds cannot be mixed with positional alternatives. With no
     * arguments the operation adds no TTL constraint, as when omitted.
     *
     * @param int|ConstructorArg|Arg|TtlRange|null ...$lifetimes Positional fixed lifetimes, references or ranges; alternatively named min/max bounds in seconds. Null is allowed only for an omitted named bound.
     * @throws InvalidArgumentException when bounds or alternatives are invalid, an unknown name is supplied, or named bounds and positional alternatives are mixed
     */
    public function __construct(
        int|ConstructorArg|Arg|TtlRange|null ...$lifetimes,
    ) {
        if (array_is_list($lifetimes)) {
            $this->min = null;
            $this->max = null;
            $this->unconstrained = $lifetimes === [];
            $this->oneOf = $lifetimes === [] ? null : TtlRange::alternatives($lifetimes);

            return;
        }

        foreach (array_keys($lifetimes) as $name) {
            if ($name !== 'min' && $name !== 'max') {
                throw new InvalidArgumentException('Use positional lifetime alternatives or named min/max bounds, never both.');
            }
        }

        $min = $lifetimes['min'] ?? null;
        $max = $lifetimes['max'] ?? null;

        if ($min instanceof TtlRange || $max instanceof TtlRange) {
            throw new InvalidArgumentException('A named lifetime bound must be integer seconds or an argument reference.');
        }

        $range = new TtlRange($min, $max);
        $this->min = $range->min;
        $this->max = $range->max;
        $this->unconstrained = false;
        $this->oneOf = null;
    }
}
