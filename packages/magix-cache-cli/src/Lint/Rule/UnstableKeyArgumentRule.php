<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint\Rule;

use function in_array;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Lint\Diagnostic;
use Magix\Cache\Cli\Lint\LintRule;
use Magix\Cache\Cli\Lint\Severity;
use Override;

use function str_contains;
use function strtolower;

/**
 * Reports key parameters that cannot be serialized into a stable key.
 */
final readonly class UnstableKeyArgumentRule implements LintRule
{
    /**
     * Declared types that never produce a stable cache key on their own.
     */
    private const array UNSTABLE = ['mixed', 'object', 'callable', 'iterable', 'closure', 'generator', 'traversable'];

    /**
     * Returns a finding for every parameter that needs a reduction.
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
            if ($parameter->ignored || $parameter->reducer !== null) {
                continue;
            }

            $type = strtolower($parameter->type ?? 'mixed');

            if (!in_array($type, self::UNSTABLE, true) && !str_contains($type, 'closure')) {
                continue;
            }

            $diagnostics[] = new Diagnostic(
                rule: 'unstable-key-argument',
                severity: Severity::Warning,
                boundary: $boundary->id(),
                file: $boundary->file,
                line: $boundary->line,
                message: '$'.$parameter->name.' is declared as '.($parameter->type ?? 'untyped').' and is used in the cache key.',
                hint: 'Reduce it with #[CacheKey], or exclude it with #[CacheIgnore] when it cannot change the result.',
            );
        }

        return $diagnostics;
    }
}
