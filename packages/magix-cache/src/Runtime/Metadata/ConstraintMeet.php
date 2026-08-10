<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Metadata;

use function min;

/**
 * Calculates meet operations for nullable cache constraints.
 *
 * @internal
 */
final readonly class ConstraintMeet
{
    /**
     * Returns the earlier finite expiration, treating null as infinity.
     */
    public function expiration(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return min($left, $right);
    }
}
