<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

/**
 * Normalizes a union of lifetime intervals without filling its gaps.
 *
 * Composition computes pairwise minimum images, not set intersection.
 * Branch correlations are not assumed: every combination remains possible.
 */
final readonly class TtlRangeSet
{
    /**
     * @var non-empty-list<TtlInterval> Sorted, disjoint intervals.
     */
    public array $intervals;

    /**
     * Sorts alternatives and merges only overlapping intervals.
     */
    public function __construct(TtlInterval $first, TtlInterval ...$rest)
    {
        $ordered = [$first, ...$rest];
        usort($ordered, static fn (TtlInterval $a, TtlInterval $b): int => $a->compare($b));
        $normalized = [];

        foreach ($ordered as $interval) {
            $last = array_key_last($normalized);

            if ($last !== null && $normalized[$last]->overlaps($interval)) {
                $normalized[$last] = $normalized[$last]->cover($interval);
            } else {
                $normalized[] = $interval;
            }
        }

        $this->intervals = $normalized;
    }

    /**
     * Returns every possible earlier lifetime across two independent sets.
     */
    public function meet(self $other): self
    {
        $intervals = [];

        foreach ($this->intervals as $first) {
            foreach ($other->intervals as $second) {
                $intervals[] = $first->meet($second);
            }
        }

        return new self(...$intervals);
    }

    /**
     * Returns the proven lower bound of the complete union.
     */
    public function lowerBound(): ?int
    {
        return $this->intervals[0]->min;
    }

    /**
     * Returns the proven upper bound of the complete union.
     */
    public function upperBound(): ?int
    {
        return $this->intervals[array_key_last($this->intervals)]->max;
    }

    /**
     * Joins possible lifetimes with slash notation and one seconds suffix.
     */
    public function label(): string
    {
        return implode('/', array_map(static fn (TtlInterval $interval): string => $interval->label(), $this->intervals)).'s';
    }

    /**
     * Exposes the normalized alternatives without discarding their gaps.
     *
     * @return non-empty-list<array{min: int|null, max: int|null}>
     */
    public function bounds(): array
    {
        return array_map(static fn (TtlInterval $interval): array => $interval->bounds(), $this->intervals);
    }
}
