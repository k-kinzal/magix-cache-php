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
 * Reports declared strategy compositions that cannot work as written.
 *
 * A #[UseStrategy] whose class has no create(), whose arguments cannot be
 * bound to create(), or whose contracts reference parameters that do not
 * exist fails when the boundary is resolved or leaves the analysis with a
 * declaration error; both are findings, not silent unknowns.
 */
final readonly class UnresolvedStrategyRule implements LintRule
{
    /**
     * Returns a finding for every strategy declaration problem.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);

        $strategy = $node->effect->strategy;

        if ($strategy === null) {
            return [];
        }

        $diagnostics = [];

        foreach ($strategy->problems as $problem) {
            $diagnostics[] = new Diagnostic(
                rule: 'unresolved-strategy',
                severity: Severity::Error,
                boundary: $node->boundary->id(),
                file: $node->boundary->file,
                line: $node->boundary->useStrategy->line ?? $node->boundary->line,
                message: $problem.'.',
                hint: 'Declare arguments and contract references that match the construction code of the strategy.',
            );
        }

        return $diagnostics;
    }
}
