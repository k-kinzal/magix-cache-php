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
            $effect = new CacheEffect(TtlEstimate::unknown(condition: 'recursive dependency, not analyzed'), $boundary->scope() ?? Visibility::Shared, visibilityUnknown: $boundary->scope() !== Visibility::NoStore);

            return new CacheNode($boundary, $effect, [], ['recursive dependency, not expanded again']);
        }

        if ($depth < 1) {
            $constraint = $boundary->dependencies === []
                ? new DependencyConstraint()
                : new DependencyConstraint(TtlEstimate::unknown(condition: 'dependencies beyond the depth limit were not analyzed'), visibilityUnknown: true);
            $notes = $boundary->dependencies === [] ? [] : ['depth limit reached, dependencies not expanded; increase --depth to analyze further'];

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
        $calls = [];

        foreach ($boundary->dependencies as $dependency) {
            $candidates = $this->catalog->candidates($dependency->class, $dependency->method, includeEntryPoints: true);

            if (count($candidates) > 1) {
                $notes[] = $dependency->class.'::'.$dependency->method.' resolves to '.count($candidates).' implementations';
            }

            foreach ($candidates as $candidate) {
                $composed = $candidate->isCacheBoundary || (!$boundary->isCacheBoundary && $candidate->dependencies !== []);

                if (isset($seen[$candidate->id()])) {
                    $calls[$dependency->class.'::'.$dependency->method][] = $seen[$candidate->id()];
                    continue;
                }

                $child = $this->build($candidate, $depth - 1, $visited, $includeUncached);
                $seen[$candidate->id()] = $child;
                $calls[$dependency->class.'::'.$dependency->method][] = $child;
                $paths = $boundary->isCacheBoundary && !$candidate->isCacheBoundary ? CacheGap::through($child, [$boundary]) : [];
                $gaps = [...$gaps, ...($this->unverified($boundary, $child, $dependency->class.'::'.$dependency->method) ? $paths : [])];

                if ($includeUncached || $composed || $paths !== []) {
                    $children[] = $child;
                }

                if ($composed) {
                    $constraints[] = $child;
                }
            }
        }

        return $this->finish($boundary, $children, $constraints, $notes, $gaps, $calls);
    }

    /**
     * @param list<CacheNode> $children
     * @param list<CacheNode> $constraints
     * @param list<string> $notes
     * @param list<CacheGap> $gaps
     * @param array<string, list<CacheNode>> $calls
     */
    public function finish(BoundaryDeclaration $boundary, array $children, array $constraints, array $notes, array $gaps, array $calls): CacheNode
    {
        $strategy = $this->strategies->resolve($boundary);
        $variants = $boundary->metadataFlow === null ? null : $this->effects->applyAlternatives(
            $boundary,
            (new FlowEffects())->evaluate($boundary->metadataFlow, $calls),
            $strategy,
        );
        foreach ($variants ?? [] as $variant) {
            if (!$variant->analyzed) {
                $notes[] = 'returned cache metadata is not analyzed';
                break;
            }
        }

        $effect = $variants !== null && $variants !== []
            ? (count($variants) === 1 ? $variants[0]->effect : (new AlternativeEffects())->summarize($variants))
            : $this->effects->calculate($boundary, $this->effects->constrain($constraints, $gaps !== []), $strategy);

        if ($variants === []) {
            $effect = new CacheEffect(TtlEstimate::unknown(condition: 'method has no normal return'));
        }

        return new CacheNode(
            $boundary,
            $effect,
            $children,
            $notes,
            $gaps,
            metadataVariants: $variants,
        );
    }

    /**
     * A proven return or extraction is not an analysis gap just because it crosses an ordinary method.
     */
    public function unverified(BoundaryDeclaration $boundary, CacheNode $child, string $target): bool
    {
        if ($boundary->metadataFlow === null || $child->metadataVariants === null) {
            return true;
        }

        if (!$boundary->metadataFlow->hasUnknown() && !$boundary->metadataFlow->references($target)) {
            return false;
        }

        if ($boundary->metadataFlow->hasUnknown()) {
            return true;
        }

        foreach ($child->metadataVariants as $variant) {
            if (!$variant->analyzed) {
                return true;
            }
        }

        return false;
    }
}
