<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

/**
 * Holds the composed contract of the strategy a boundary declares.
 *
 * The lifetime belongs to the outermost expiration writer on the fetch
 * return path. Dependency and policy deadlines have lower priority. Steps
 * keep each child's candidate even when a later override replaces it.
 * overridesExpiration is false for pass-through and null for opaque code.
 */
final readonly class StrategyEffect
{
    /**
     * Creates the analyzed effect of one declared strategy composition.
     *
     * @param string $label The declared construction, such as ProductCacheStrategy::create(min: 60).
     * @param TtlEstimate $ttl Winning expiration override on the normal origin path.
     * @param list<StrategyStep> $steps Contributions in composition order.
     * @param bool|null $overridesExpiration Whether expiration is definitely replaced; null for an opaque final writer.
     * @param list<string> $problems Declarations that cannot work as written.
     * @param list<ExpirationEstimate> $expirations Candidate daily expiration constraints.
     * @param bool $metadataUnknown Whether non-expiration metadata can be overridden by custom code.
     */
    public function __construct(
        public string $label,
        public TtlEstimate $ttl,
        public array $steps = [],
        public ?bool $overridesExpiration = null,
        public array $problems = [],
        public array $expirations = [],
        public bool $metadataUnknown = false,
    ) {
    }
}
