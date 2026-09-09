<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function strrpos;
use function substr;

/**
 * Holds the analyzed lifetime contribution of one composed strategy.
 */
final readonly class StrategyStep
{
    /**
     * Creates one analyzed composition step.
     *
     * @param string $strategy Class name of the composed strategy.
     * @param TtlEstimate $ttl Candidate constraint this step contributes.
     * @param bool $assumed True when an explicit assumption replaced the contract.
     * @param list<ExpirationEstimate> $expirations Candidate daily expiration constraints.
     */
    public function __construct(
        public string $strategy,
        public TtlEstimate $ttl,
        public bool $assumed = false,
        public array $expirations = [],
    ) {
    }

    /**
     * Returns the strategy class name without its namespace.
     */
    public function shortName(): string
    {
        $separator = strrpos($this->strategy, '\\');

        return $separator === false ? $this->strategy : substr($this->strategy, $separator + 1);
    }
}
