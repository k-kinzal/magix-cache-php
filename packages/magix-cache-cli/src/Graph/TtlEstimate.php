<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use InvalidArgumentException;
use JsonSerializable;

use function min;

use Override;

/**
 * Holds what the analyzer can prove about the lifetime of one boundary.
 *
 * The value keeps "no constraint is declared" apart from "the constraint
 * depends on runtime values": an unknown lifetime is never converted into a
 * concrete number or into the absence of a constraint. An unknown lifetime
 * may still carry proven bounds — a lower bound the constraint never goes
 * under and an upper bound it never exceeds; a missing bound is
 * undetermined, not unlimited. The analyzer works with durations and
 * bounds, a different value domain from the runtime's absolute expirations.
 */
final readonly class TtlEstimate implements JsonSerializable
{
    /**
     * Creates an estimate; the shape of each state is fixed.
     *
     * @param int|null $seconds Determined lifetime, carried only by Known.
     * @param int|null $upperBound Bound a stored entry cannot outlive, carried only by Unknown.
     * @param string|null $reason Why the value holds: a derivation for Known, a runtime condition for Unknown, the problem for Invalid.
     * @param int|null $lowerBound Bound the lifetime never goes under, carried only by Unknown.
     * @param TtlRangeSet|null $alternatives Disjoint alternatives whose enclosing bounds match this estimate.
     * @param bool $finite Whether a finite expiration is guaranteed even though its lifetime depends on runtime values.
     * @throws InvalidArgumentException when a field is combined with a state that cannot carry it
     */
    public function __construct(
        public TtlEstimateState $state,
        public ?int $seconds = null,
        public ?int $upperBound = null,
        public ?string $reason = null,
        public ?int $lowerBound = null,
        public ?TtlRangeSet $alternatives = null,
        public bool $finite = false,
    ) {
        if (($state === TtlEstimateState::Known) !== ($seconds !== null)) {
            throw new InvalidArgumentException('A lifetime in seconds is carried by a Known estimate and nothing else.');
        }

        if (($upperBound !== null || $lowerBound !== null) && $state !== TtlEstimateState::Unknown) {
            throw new InvalidArgumentException('A bound is carried by an Unknown estimate and nothing else.');
        }

        if ($state === TtlEstimateState::Invalid && $reason === null) {
            throw new InvalidArgumentException('An Invalid estimate must name its problem.');
        }

        if (($seconds !== null && $seconds < 0) || ($upperBound !== null && $upperBound < 0) || ($lowerBound !== null && $lowerBound < 0)) {
            throw new InvalidArgumentException('A lifetime must be zero or greater.');
        }

        if ($lowerBound !== null && $upperBound !== null && $upperBound < $lowerBound) {
            throw new InvalidArgumentException('A lifetime upper bound cannot be below the lower bound.');
        }

        if ($alternatives !== null && ($state !== TtlEstimateState::Unknown
            || $lowerBound !== $alternatives->lowerBound() || $upperBound !== $alternatives->upperBound())) {
            throw new InvalidArgumentException('Lifetime alternatives require an unknown estimate with matching enclosing bounds.');
        }
    }

    /**
     * Returns a statically determined finite lifetime.
     *
     * @param string|null $reason How the value was derived, when it is not the declared one.
     */
    public static function known(int $seconds, ?string $reason = null): self
    {
        return new self(TtlEstimateState::Known, seconds: $seconds, reason: $reason);
    }

    /**
     * Returns the certainty that no expiration constraint is provided.
     */
    public static function unconstrained(): self
    {
        return new self(TtlEstimateState::Unconstrained);
    }

    /**
     * Returns a lifetime that only the runtime can decide.
     *
     * @param int|null $upperBound Bound the lifetime cannot exceed if the boundary stores at all.
     * @param string|null $condition What must hold at runtime for a lifetime to exist.
     * @param int|null $lowerBound Bound the lifetime never goes under.
     * @param bool $finite Proof that successful evaluation supplies a finite expiration even when its numeric bounds are undetermined.
     */
    public static function unknown(?int $upperBound = null, ?string $condition = null, ?int $lowerBound = null, bool $finite = false): self
    {
        return new self(TtlEstimateState::Unknown, upperBound: $upperBound, reason: $condition, lowerBound: $lowerBound, finite: $finite);
    }

    /**
     * Returns a declaration that cannot work as written.
     */
    public static function invalid(string $problem): self
    {
        return new self(TtlEstimateState::Invalid, reason: $problem);
    }

    /**
     * Returns an estimate of alternative points and intervals, preserving gaps.
     */
    public static function oneOf(TtlInterval $first, TtlInterval ...$rest): self
    {
        return self::fromRanges(new TtlRangeSet($first, ...$rest), finite: true);
    }

    /**
     * Collapses only a single proven value; other alternatives stay unknown.
     *
     * @param bool $finite Whether all alternatives promise a finite expiration.
     */
    public static function fromRanges(TtlRangeSet $ranges, ?string $condition = null, bool $finite = false): self
    {
        $lower = $ranges->lowerBound();
        $upper = $ranges->upperBound();

        if ($lower !== null && $lower === $upper && $condition === null) {
            return self::known($lower);
        }

        return new self(
            TtlEstimateState::Unknown,
            upperBound: $upper,
            reason: $condition,
            lowerBound: $lower,
            alternatives: count($ranges->intervals) > 1 ? $ranges : null,
            finite: $finite,
        );
    }

    /**
     * Returns this value's possible intervals for lifetime composition.
     */
    public function ranges(): TtlRangeSet
    {
        return $this->alternatives ?? new TtlRangeSet(new TtlInterval(
            $this->seconds ?? $this->lowerBound,
            $this->seconds ?? $this->upperBound,
        ));
    }

    /**
     * Adds a runtime condition while preserving every possible lifetime.
     */
    public function withCondition(string $condition): self
    {
        if ($this->state === TtlEstimateState::Invalid || $this->state === TtlEstimateState::Unconstrained) {
            return $this;
        }

        return self::fromRanges($this->ranges(), $condition, $this->hasFiniteExpiration());
    }

    /**
     * Reports the proof that a successful boundary supplies a finite expiration.
     *
     * Unknown bounds do not erase a strategy's promise to add a finite TTL.
     */
    public function hasFiniteExpiration(): bool
    {
        return $this->state === TtlEstimateState::Known || ($this->state === TtlEstimateState::Unknown && $this->finite);
    }

    /**
     * Returns the stricter combination of two estimates.
     *
     * Invalid dominates, Unconstrained is the identity, and two Known values
     * keep the earlier one. Every other combination keeps the provable
     * bounds: the upper bound is the tightest one any side guarantees, the
     * lower bound survives only when both sides guarantee one, and a
     * combination pinned to one value without a runtime condition becomes
     * Known.
     */
    public function meet(self $other): self
    {
        if ($this->state === TtlEstimateState::Invalid) {
            return $this;
        }

        if ($other->state === TtlEstimateState::Invalid) {
            return $other;
        }

        if ($this->state === TtlEstimateState::Unconstrained) {
            return $other;
        }

        if ($other->state === TtlEstimateState::Unconstrained) {
            return $this;
        }

        if ($this->seconds !== null && $other->seconds !== null) {
            return $this->seconds <= $other->seconds ? $this : $other;
        }

        return self::fromRanges(
            $this->ranges()->meet($other->ranges()),
            $this->condition($other),
            $this->hasFiniteExpiration() || $other->hasFiniteExpiration(),
        );
    }

    /**
     * Returns the tightest upper bound two combined estimates guarantee.
     */
    public function bound(self $other): ?int
    {
        $first = $this->seconds ?? $this->upperBound;
        $second = $other->seconds ?? $other->upperBound;

        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return min($first, $second);
    }

    /**
     * Returns the lower bound two combined estimates still guarantee.
     *
     * The combination takes the earlier constraint, so the guarantee only
     * survives when both sides carry one.
     */
    public function floor(self $other): ?int
    {
        $first = $this->seconds ?? $this->lowerBound;
        $second = $other->seconds ?? $other->lowerBound;

        if ($first === null || $second === null) {
            return null;
        }

        return min($first, $second);
    }

    /**
     * Returns the runtime condition that survives combining two estimates.
     */
    public function condition(self $other): ?string
    {
        if ($this->state === TtlEstimateState::Unknown && $this->reason !== null) {
            return $this->reason;
        }

        return $other->state === TtlEstimateState::Unknown ? $other->reason : null;
    }

    /**
     * Reports whether two estimates say exactly the same thing.
     */
    public function equals(self $other): bool
    {
        return $this->state === $other->state
            && $this->seconds === $other->seconds
            && $this->upperBound === $other->upperBound
            && $this->lowerBound === $other->lowerBound
            && $this->alternatives?->bounds() === $other->alternatives?->bounds()
            && $this->hasFiniteExpiration() === $other->hasFiniteExpiration()
            && $this->reason === $other->reason;
    }

    /**
     * Returns the estimate rendered for human readable output.
     *
     * A range keeps what is proven: "30-60s" for both bounds, "30-?s" when
     * only the lower bound is known — the ? is undetermined, not unlimited —
     * and "≤60s" when only the upper bound is known.
     */
    public function label(): string
    {
        if ($this->alternatives !== null) {
            return $this->alternatives->label();
        }

        if ($this->seconds !== null) {
            return $this->seconds.'s';
        }

        if ($this->lowerBound !== null) {
            return $this->upperBound === null
                ? $this->lowerBound.'-?s'
                : $this->lowerBound.'-'.$this->upperBound.'s';
        }

        if ($this->upperBound !== null) {
            return '≤'.$this->upperBound.'s';
        }

        return $this->state->value;
    }

    /**
     * Returns the estimate as plain data for machine readable output.
     *
     * @return array{state: string, seconds: int|null, lowerBound: int|null, upperBound: int|null, reason: string|null, ranges?: non-empty-list<array{min: int|null, max: int|null}>, finite?: true}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $serialized = [
            'state' => $this->state->value,
            'seconds' => $this->seconds,
            'lowerBound' => $this->lowerBound,
            'upperBound' => $this->upperBound,
            'reason' => $this->reason,
        ];

        if ($this->alternatives !== null) {
            $serialized['ranges'] = $this->alternatives->bounds();
        }

        if ($this->state === TtlEstimateState::Unknown && $this->finite) {
            $serialized['finite'] = true;
        }

        return $serialized;
    }
}
