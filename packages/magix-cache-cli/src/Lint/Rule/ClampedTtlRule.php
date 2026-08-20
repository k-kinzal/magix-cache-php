<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use function is_int;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Override;

/**
 * Reports fixed TTLs that a shorter dependency always shortens.
 */
final readonly class ClampedTtlRule implements LintRule
{
    /**
     * Returns a finding when the declared TTL can never be reached.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);
        $boundary = $node->boundary;
        $declared = $boundary->policy?->ttl;
        $effective = $node->effect->ttl;

        if (!is_int($declared) || $effective === null || $effective >= $declared) {
            return [];
        }

        return [new Diagnostic(
            rule: 'clamped-ttl',
            severity: Severity::Notice,
            boundary: $boundary->id(),
            file: $boundary->file,
            line: $boundary->line,
            message: 'The declared ttl of '.$declared.'s is always clamped to '.$effective.'s by a dependency.',
            hint: 'Declare ttl: '.$effective.', use Ttl::Auto, or set clamp: false when the boundary may outlive its dependency.',
        )];
    }
}
