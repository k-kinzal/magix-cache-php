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
     * @param array{ttl?: string, visibility?: string} $localRestrictions Local settings that provably restrict composed constraints; absent keys make no claim.
     * @param list<ExpirationEstimate> $expirationConstraints Daily candidates that may only be shortened by further composition.
     */
    public function __construct(
        ?TtlEstimate $ttl = null,
        public Visibility $visibility = Visibility::Shared,
        public bool $storable = false,
        public array $tags = [],
        public ?string $visibilityReason = null,
        public array $problems = [],
        public ?StrategyEffect $strategy = null,
        public bool $visibilityUnknown = false,
        public bool $tagsUnknown = false,
        public array $localRestrictions = [],
        public array $expirationConstraints = [],
    ) {
        $this->ttl = $ttl ?? TtlEstimate::unknown();
    }

    /**
     * Renders the proven visibility restriction and any runtime uncertainty.
     */
    public function visibilityLabel(): string
    {
        return strtolower($this->visibility->name).($this->visibilityUnknown ? ' or stricter' : '');
    }

    /**
     * Renders proven tags separately from additional tags supplied at runtime.
     */
    public function tagsLabel(string $separator = ', '): string
    {
        $known = implode($separator, $this->tags);

        if ($this->tagsUnknown) {
            return $known === '' ? 'runtime tags' : $known.' + runtime tags';
        }

        return $known === '' ? '-' : $known;
    }
}
