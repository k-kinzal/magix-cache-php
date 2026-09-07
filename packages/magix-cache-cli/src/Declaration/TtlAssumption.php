<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one explicit analysis assumption declared on a composition.
 *
 * The assumption stands in for the lifetime contract of exactly one composed
 * strategy when that contract cannot be read statically. It is an input to
 * this analysis only: it changes nothing at runtime and marks no other
 * effect as analyzed.
 */
final readonly class TtlAssumption
{
    /**
     * Creates a statically read lifetime assumption.
     *
     * @param string $strategy Class name of the composed strategy the assumption covers.
     * @param int|ContractReference|Unresolved|null $min
     * @param int|ContractReference|Unresolved|null $max
     */
    public function __construct(
        public string $strategy,
        public int|ContractReference|Unresolved|null $min = null,
        public int|ContractReference|Unresolved|null $max = null,
        public bool $unconstrained = false,
    ) {
    }
}
