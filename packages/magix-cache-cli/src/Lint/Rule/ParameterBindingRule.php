<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\ParameterEffects;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Override;

/**
 * Reports invalid parameter configuration bindings before execution.
 */
final readonly class ParameterBindingRule implements LintRule
{
    /**
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);
        $diagnostics = [];

        foreach ((new ParameterEffects())->problems($node->boundary) as $problem) {
            $diagnostics[] = new Diagnostic(
                rule: 'invalid-parameter-binding',
                severity: Severity::Error,
                boundary: $node->boundary->id(),
                file: $node->boundary->file,
                line: $node->boundary->line,
                message: $problem.'.',
                hint: 'Use keyed, non-variadic parameters with compatible values and one source per strategy destination.',
            );
        }

        return $diagnostics;
    }
}
