<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Magix\Cache\Runtime\Metadata\Visibility;
use Override;

/**
 * Reports parameters that are both ignored and scoped below NoStore.
 */
final readonly class ScopedIgnoreConflictRule implements LintRule
{
    /**
     * Returns a finding for every parameter the runtime rejects.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        unset($catalog);
        $boundary = $node->boundary;
        $diagnostics = [];

        foreach ($boundary->parameters as $parameter) {
            if (!$parameter->ignored || $parameter->scope === null || $parameter->scope === Visibility::NoStore) {
                continue;
            }

            $diagnostics[] = new Diagnostic(
                rule: 'scoped-ignore-conflict',
                severity: Severity::Error,
                boundary: $boundary->id(),
                file: $boundary->file,
                line: $boundary->line,
                message: '$'.$parameter->name.' is ignored and scoped, so resolving the boundary throws an InvalidArgumentException.',
                hint: 'Remove #[CacheIgnore], or scope the parameter with Visibility::NoStore.',
            );
        }

        return $diagnostics;
    }
}
