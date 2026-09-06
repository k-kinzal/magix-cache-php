<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

/**
 * Holds the composed contract of the strategy a boundary declares.
 *
 * The lifetime is the candidate constraint the composed strategies add on
 * the normal origin path, before the policy and the upstream expiration are
 * applied; the candidate a strategy chooses and the effective lifetime that
 * survives composition stay two different things. Whether a finite
 * constraint is added at all is kept as its own three-valued answer, because
 * a missing contract never counts as "adds nothing".
 */
final readonly class StrategyEffect
{
    /**
     * Creates the analyzed effect of one declared strategy composition.
     *
     * @param string $label The declared construction, such as ProductCacheStrategy::create(min: 60).
     * @param TtlEstimate $ttl Candidate constraint the composition adds on the normal origin path.
     * @param list<StrategyStep> $steps Contributions in composition order.
     * @param bool|null $addsConstraint Whether a finite constraint is definitely added; null when undeclared parts leave it open.
     * @param list<string> $problems Declarations that cannot work as written.
     */
    public function __construct(
        public string $label,
        public TtlEstimate $ttl,
        public array $steps = [],
        public ?bool $addsConstraint = null,
        public array $problems = [],
    ) {
    }
}
