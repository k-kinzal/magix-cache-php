<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

/**
 * Holds one lifetime contract normalized from its declaration syntax.
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
     * @param list<self>|null $oneOf Alternative finite constraints, kept separately instead of taking their envelope.
     * @param list<string> $problems Statically malformed declaration syntax.
     */
    public function __construct(
        public int|ContractReference|Unresolved|null $min = null,
        public int|ContractReference|Unresolved|null $max = null,
        public bool $unconstrained = false,
        public ?array $oneOf = null,
        public array $problems = [],
    ) {
    }

    /**
     * Reports declaration shapes that cannot construct the public contract.
     *
     * @return list<string>
     */
    public function declarationProblems(): array
    {
        $problems = $this->problems;

        if ($this->oneOf !== null) {
            if ($this->oneOf === []) {
                $problems[] = 'lifetime alternatives must be a non-empty list';
            }

            if ($this->min !== null || $this->max !== null || $this->unconstrained) {
                $problems[] = 'lifetime alternatives cannot be combined with bounds or unconstrained';
            }
        } elseif ($this->unconstrained) {
            if ($this->min !== null || $this->max !== null) {
                $problems[] = 'an unconstrained lifetime contract cannot also declare bounds';
            }
        } elseif ($this->min === null && $this->max === null) {
            $problems[] = 'a lifetime range must declare at least one bound';
        }

        return $problems;
    }
}
