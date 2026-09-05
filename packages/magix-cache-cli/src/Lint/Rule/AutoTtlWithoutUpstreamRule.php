<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\EffectCalculator;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Magix\Cache\Runtime\Policy\Ttl;
use Override;

/**
 * Reports derived TTLs whose finite upstream expiration never exists.
 *
 * An upstream that is confirmed unconstrained is an error, because applying
 * the policy throws at runtime. An upstream the analyzer cannot decide is
 * only a conditional requirement and is reported as a notice.
 */
final readonly class AutoTtlWithoutUpstreamRule implements LintRule
{
    /**
     * Creates the rule.
     */
    public function __construct(private EffectCalculator $effects = new EffectCalculator())
    {
    }

    /**
     * Returns a finding when a derived TTL has nothing to derive from.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);
        $boundary = $node->boundary;
        $declared = $boundary->policy?->ttl;

        if (!$declared instanceof Ttl || $boundary->suppliesMetadata || $boundary->hasDynamicTtl) {
            return [];
        }

        $upstream = $this->effects->constrain($node->children)->ttl;

        if ($upstream->state === TtlEstimateState::Unconstrained) {
            return [new Diagnostic(
                rule: 'auto-ttl-without-upstream',
                severity: Severity::Error,
                boundary: $boundary->id(),
                file: $boundary->file,
                line: $boundary->line,
                message: 'Ttl::'.$declared->name.' has no dependency with a finite expiration, so applying the policy throws a LogicException.',
                hint: 'Declare a fixed ttl, depend on a cached query, or supply CacheMetadata with an expiration.',
            )];
        }

        if ($upstream->state === TtlEstimateState::Unknown) {
            return [new Diagnostic(
                rule: 'auto-ttl-without-upstream',
                severity: Severity::Notice,
                boundary: $boundary->id(),
                file: $boundary->file,
                line: $boundary->line,
                message: 'Ttl::'.$declared->name.' requires a finite upstream expiration at runtime, and whether one exists cannot be determined statically.',
                hint: 'Confirm the dependencies always carry a finite expiration, or declare a fixed ttl.',
            )];
        }

        return [];
    }
}
