<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one declared lifetime contract exactly as written.
 *
 * The contract describes the candidate constraint an operation adds on the
 * normal origin path: bounds may be fixed values or explicit references, a
 * missing bound is undetermined rather than unlimited, and unconstrained
 * declares that no lifetime constraint is added at all.
 */
final readonly class TtlContract
{
    /**
     * Creates a statically read lifetime contract.
     *
     * @param int|ContractReference|Unresolved|null $min
     * @param int|ContractReference|Unresolved|null $max
     */
    public function __construct(
        public int|ContractReference|Unresolved|null $min = null,
        public int|ContractReference|Unresolved|null $max = null,
        public bool $unconstrained = false,
    ) {
    }
}
