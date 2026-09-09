<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function count;
use function in_array;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Metadata\Visibility;

/**
 * Expands a boundary or an uncached entry point into its cache dependency tree.
 */
final readonly class CacheTree
{
    private StrategyResolver $strategies;

    /**
     * Creates a tree builder for one catalog.
     */
    public function __construct(
        private Catalog $catalog,
        private EffectCalculator $effects = new EffectCalculator(),
        ?StrategyResolver $strategies = null,
    ) {
        $this->strategies = $strategies ?? new StrategyResolver($catalog);
    }

    /**
     * Returns a tree rooted at a boundary or an uncached method whose callees are composed.
     *
     * @param list<string> $visited Boundary identifiers already on the current path.
     * @param bool $includeUncached Include ordinary calls beyond the paths needed to report cache propagation gaps.
     */
    public function build(BoundaryDeclaration $boundary, int $depth = 8, array $visited = [], bool $includeUncached = false): CacheNode
    {
        $id = $boundary->id();

        if (in_array($id, $visited, true)) {
            $effect = new CacheEffect(TtlEstimate::unknown(condition: 'recursive dependency, not analyzed'), $boundary->scope(), visibilityUnknown: $boundary->scope() !== Visibility::NoStore);

            return new CacheNode($boundary, $effect, [], ['recursive dependency, not expanded again']);
        }

        if ($depth < 1) {
            $constraint = $boundary->dependencies === []
                ? new DependencyConstraint()
                : new DependencyConstraint(TtlEstimate::unknown(condition: 'dependencies beyond the depth limit were not analyzed'), visibilityUnknown: true);
            $notes = $boundary->dependencies === [] ? [] : ['depth limit reached, dependencies not expanded'];

            return new CacheNode($boundary, $this->effects->calculate($boundary, $constraint, $this->strategies->resolve($boundary)), [], $notes);
        }

        return $this->expand($boundary, $depth, [...$visited, $id], $includeUncached);
    }

    /**
     * Expands calls while keeping inspection-only paths out of cache composition.
     *
     * @param list<string> $visited Identifiers on the current path, including this boundary.
     */
    public function expand(BoundaryDeclaration $boundary, int $depth, array $visited, bool $includeUncached): CacheNode
    {
        $children = [];
        $constraints = [];
        $notes = [];
        $seen = [];
        $gaps = [];

        foreach ($boundary->dependencies as $dependency) {
            $candidates = $this->catalog->candidates($dependency->class, $dependency->method, includeEntryPoints: true);

            if (count($candidates) > 1) {
                $notes[] = $dependency->class.'::'.$dependency->method.' resolves to '.count($candidates).' implementations';
            }

            foreach ($candidates as $candidate) {
                $composed = $candidate->isCacheBoundary || (!$boundary->isCacheBoundary && $candidate->dependencies !== []);

                if (isset($seen[$candidate->id()])) {
                    continue;
                }

                $seen[$candidate->id()] = true;
                $child = $this->build($candidate, $depth - 1, $visited, $includeUncached);
                $paths = $boundary->isCacheBoundary && !$candidate->isCacheBoundary ? CacheGap::through($child, [$boundary]) : [];
                $gaps = [...$gaps, ...$paths];

                if ($includeUncached || $composed || $paths !== []) {
                    $children[] = $child;
                }

                if ($composed) {
                    $constraints[] = $child;
                }
            }
        }

        return new CacheNode(
            $boundary,
            $this->effects->calculate($boundary, $this->effects->constrain($constraints, $gaps !== []), $this->strategies->resolve($boundary)),
            $children,
            $notes,
            $gaps,
        );
    }
}
