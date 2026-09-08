<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use InvalidArgumentException;

/**
 * Carries one inclusive interval of possible lifetimes in the analysis domain.
 *
 * Null is a missing proof of a bound, not an unconstrained cache lifetime.
 */
final readonly class TtlInterval
{
    /**
     * Records known bounds, including a singleton when they are equal.
     *
     * @throws InvalidArgumentException when a bound is negative or the bounds contradict
     */
    public function __construct(public ?int $min = null, public ?int $max = null)
    {
        if (($min !== null && $min < 0) || ($max !== null && $max < 0)
            || ($min !== null && $max !== null && $max < $min)) {
            throw new InvalidArgumentException('A lifetime interval requires non-negative, ordered bounds.');
        }
    }

    /**
     * Returns the image of min(a, b) for every lifetime in the two intervals.
     */
    public function meet(self $other): self
    {
        return new self(
            $this->min === null || $other->min === null ? null : min($this->min, $other->min),
            $this->max === null || $other->max === null ? $this->max ?? $other->max : min($this->max, $other->max),
        );
    }

    /**
     * Orders intervals by their lower bound; a missing bound comes first.
     */
    public function compare(self $other): int
    {
        if ($this->min === null || $other->min === null) {
            return ($this->min === null ? 0 : 1) <=> ($other->min === null ? 0 : 1);
        }

        return $this->min <=> $other->min;
    }

    /**
     * Reports whether this interval overlaps a later interval in bound order.
     */
    public function overlaps(self $later): bool
    {
        return $this->max === null || $later->min === null || $later->min <= $this->max;
    }

    /**
     * Returns the enclosing bounds of overlapping alternatives.
     */
    public function cover(self $other): self
    {
        return new self(
            $this->min === null || $other->min === null ? null : min($this->min, $other->min),
            $this->max === null || $other->max === null ? null : max($this->max, $other->max),
        );
    }

    /**
     * Renders a point or interval without a unit, for joining alternatives.
     */
    public function label(): string
    {
        if ($this->min !== null) {
            return $this->min === $this->max ? (string) $this->min : $this->min.'-'.($this->max ?? '?');
        }

        return $this->max === null ? '?' : '≤'.$this->max;
    }

    /**
     * Returns both bounds for machine-readable output and comparison.
     *
     * @return array{min: int|null, max: int|null}
     */
    public function bounds(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }
}
