<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Lint;

use function array_merge;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Lint\Rule\AutoTtlWithoutUpstreamRule;
use Magix\Cache\Cli\Lint\Rule\ClampedTtlRule;
use Magix\Cache\Cli\Lint\Rule\MissingPolicyRule;
use Magix\Cache\Cli\Lint\Rule\ScopedIgnoreConflictRule;
use Magix\Cache\Cli\Lint\Rule\UnscopedPrivateKeyRule;
use Magix\Cache\Cli\Lint\Rule\UnstableKeyArgumentRule;

use function usort;

/**
 * Runs every lint rule over the boundaries of one catalog.
 */
final readonly class CacheLinter
{
    /**
     * Rules applied to every boundary.
     *
     * @var list<LintRule>
     */
    private array $rules;

    /**
     * Creates a linter with the default rule set.
     *
     * @param list<LintRule>|null $rules
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? [
            new MissingPolicyRule(),
            new AutoTtlWithoutUpstreamRule(),
            new ScopedIgnoreConflictRule(),
            new UnscopedPrivateKeyRule(),
            new UnstableKeyArgumentRule(),
            new ClampedTtlRule(),
        ];
    }

    /**
     * Returns every finding of every rule, ordered by location.
     *
     * @return list<Diagnostic>
     */
    public function inspect(Catalog $catalog, int $depth = 8): array
    {
        $tree = new CacheTree($catalog);
        $diagnostics = [];

        foreach ($catalog->boundaries() as $boundary) {
            $node = $tree->build($boundary, $depth);

            foreach ($this->rules as $rule) {
                $diagnostics = array_merge($diagnostics, $rule->check($node, $catalog));
            }
        }

        usort(
            $diagnostics,
            static fn (Diagnostic $first, Diagnostic $second): int => [$first->file, $first->line, $first->rule]
                <=> [$second->file, $second->line, $second->rule],
        );

        return $diagnostics;
    }
}
