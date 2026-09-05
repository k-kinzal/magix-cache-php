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
 * concrete number or into the absence of a constraint. The analyzer works
 * with durations and upper bounds, a different value domain from the
 * runtime's absolute expirations.
 */
final readonly class TtlEstimate implements JsonSerializable
{
    /**
     * Creates an estimate; the shape of each state is fixed.
     *
     * @param int|null $seconds Determined lifetime, carried only by Known.
     * @param int|null $upperBound Bound a stored entry cannot outlive, carried only by Unknown.
     * @param string|null $reason Why the value holds: a derivation for Known, a runtime condition for Unknown, the problem for Invalid.
     * @throws InvalidArgumentException when a field is combined with a state that cannot carry it
     */
    public function __construct(
        public TtlEstimateState $state,
        public ?int $seconds = null,
        public ?int $upperBound = null,
        public ?string $reason = null,
    ) {
        if (($state === TtlEstimateState::Known) !== ($seconds !== null)) {
            throw new InvalidArgumentException('A lifetime in seconds is carried by a Known estimate and nothing else.');
        }

        if ($upperBound !== null && $state !== TtlEstimateState::Unknown) {
            throw new InvalidArgumentException('An upper bound is carried by an Unknown estimate and nothing else.');
        }

        if ($state === TtlEstimateState::Invalid && $reason === null) {
            throw new InvalidArgumentException('An Invalid estimate must name its problem.');
        }

        if (($seconds !== null && $seconds < 0) || ($upperBound !== null && $upperBound < 0)) {
            throw new InvalidArgumentException('A lifetime must be zero or greater.');
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
     */
    public static function unknown(?int $upperBound = null, ?string $condition = null): self
    {
        return new self(TtlEstimateState::Unknown, upperBound: $upperBound, reason: $condition);
    }

    /**
     * Returns a declaration that cannot work as written.
     */
    public static function invalid(string $problem): self
    {
        return new self(TtlEstimateState::Invalid, reason: $problem);
    }

    /**
     * Returns the stricter combination of two estimates.
     *
     * Invalid dominates, Unconstrained is the identity, two Known values keep
     * the earlier one, and Unknown absorbs a Known value as an upper bound.
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

        return self::unknown($this->bound($other), $this->condition($other));
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
            && $this->reason === $other->reason;
    }

    /**
     * Returns the estimate rendered for human readable output.
     */
    public function label(): string
    {
        if ($this->seconds !== null) {
            return $this->seconds.'s';
        }

        if ($this->state === TtlEstimateState::Unknown && $this->upperBound !== null) {
            return 'unknown (≤'.$this->upperBound.'s)';
        }

        return $this->state->value;
    }

    /**
     * Returns the estimate as plain data for machine readable output.
     *
     * @return array{state: string, seconds: int|null, upperBound: int|null, reason: string|null}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state->value,
            'seconds' => $this->seconds,
            'upperBound' => $this->upperBound,
            'reason' => $this->reason,
        ];
    }
}
