<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Magix\Cache\Runtime\Policy\Ttl;
use Override;

/**
 * Reports inherited TTLs that never receive a finite expiration.
 */
final readonly class AutoTtlWithoutUpstreamRule implements LintRule
{
    /**
     * Returns a finding when Ttl::Auto has nothing to inherit from.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);
        $boundary = $node->boundary;

        if (
            $boundary->policy?->ttl !== Ttl::Auto
            || $node->effect->ttl !== null
            || $boundary->hasStrategy
            || $boundary->suppliesMetadata
        ) {
            return [];
        }

        return [new Diagnostic(
            rule: 'auto-ttl-without-upstream',
            severity: Severity::Error,
            boundary: $boundary->id(),
            file: $boundary->file,
            line: $boundary->line,
            message: 'Ttl::Auto has no dependency with a finite expiration, so applying the policy throws a LogicException.',
            hint: 'Declare a fixed ttl, depend on a cached query, or supply CacheMetadata with an expiration.',
        )];
    }
}
