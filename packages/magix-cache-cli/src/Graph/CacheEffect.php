<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Metadata\Visibility;

/**
 * Holds the cache metadata a boundary produces once its policy is applied.
 */
final readonly class CacheEffect
{
    /**
     * What the analyzer can prove about the effective lifetime.
     */
    public TtlEstimate $ttl;

    /**
     * Creates an effective cache result.
     *
     * @param TtlEstimate|null $ttl Defaults to an Unknown lifetime when the effect was not computed.
     * @param list<string> $tags
     * @param list<string> $problems Reasons the boundary cannot work as written.
     * @param StrategyEffect|null $strategy The analyzed strategy composition, when one is declared.
     */
    public function __construct(
        ?TtlEstimate $ttl = null,
        public Visibility $visibility = Visibility::Shared,
        public bool $storable = false,
        public array $tags = [],
        public ?string $visibilityReason = null,
        public array $problems = [],
        public ?StrategyEffect $strategy = null,
    ) {
        $this->ttl = $ttl ?? TtlEstimate::unknown();
    }
}
