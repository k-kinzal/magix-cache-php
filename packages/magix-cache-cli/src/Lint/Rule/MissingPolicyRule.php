<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Override;

/**
 * Reports boundaries that declare no cache policy at all.
 */
final readonly class MissingPolicyRule implements LintRule
{
    /**
     * Returns a finding when cached() has no policy to apply.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);

        if ($node->boundary->policy !== null) {
            return [];
        }

        return [new Diagnostic(
            rule: 'missing-policy',
            severity: Severity::Error,
            boundary: $node->boundary->id(),
            file: $node->boundary->file,
            line: $node->boundary->line,
            message: 'cached() is called without a policy, so the call throws a LogicException.',
            hint: 'Add #[Cache(...)] to the method or its class, or pass a CachePolicy to cached().',
        )];
    }
}
