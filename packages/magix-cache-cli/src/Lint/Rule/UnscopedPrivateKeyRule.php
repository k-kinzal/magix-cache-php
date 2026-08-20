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
 * Reports private dependencies whose scope never reaches the caller key.
 */
final readonly class UnscopedPrivateKeyRule implements LintRule
{
    /**
     * Returns a finding for every private dependency the key cannot separate.
     *
     * @return list<Diagnostic>
     */
    #[Override]
    public function check(CacheNode $node, Catalog $catalog): array
    {
        $boundary = $node->boundary;

        if ($boundary->scope() !== Visibility::Shared) {
            return [];
        }

        $diagnostics = [];

        foreach ($boundary->dependencies as $dependency) {
            foreach ($catalog->candidates($dependency->class, $dependency->method) as $candidate) {
                $position = 0;

                foreach ($candidate->parameters as $parameter) {
                    $scoped = $parameter->scope === Visibility::Private
                        && !$parameter->ignored
                        && !$parameter->optional;
                    $forwarded = $dependency->forwarded[$position] ?? $dependency->forwarded[$parameter->name] ?? null;
                    ++$position;

                    if (!$scoped || $forwarded !== null) {
                        continue;
                    }

                    $diagnostics[] = new Diagnostic(
                        rule: 'unscoped-private-key',
                        severity: Severity::Warning,
                        boundary: $boundary->id(),
                        file: $boundary->file,
                        line: $dependency->line,
                        message: $candidate->shortId().' is private through $'.$parameter->name
                            .', but this boundary keys its own entry without that value.',
                        hint: 'Accept the value as a parameter and pass it on, or mark it with #[CacheScope] here as well.',
                    );
                }
            }
        }

        return $diagnostics;
    }
}
