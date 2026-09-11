<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function count;
use function in_array;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\Analysis\AnalysisCause;
use Magix\Cache\Cli\Graph\Analysis\CallAnalysis;
use Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis;
use Magix\Cache\Metadata\Visibility;

/**
 * Expands a boundary or an uncached entry point into its cache dependency tree.
 */
final class CacheTree
{
    private readonly StrategyResolver $strategies;

    /**
     * Results reusable across paths, keyed by boundary and expansion mode.
     *
     * @var array<string, CacheNode>
     */
    private array $memo = [];

    /**
     * How many times expansion stopped at the recursion guard.
     */
    private int $recursions = 0;

    /**
     * Creates a tree builder for one catalog.
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly EffectCalculator $effects = new EffectCalculator(),
        ?StrategyResolver $strategies = null,
    ) {
        $this->strategies = $strategies ?? new StrategyResolver($catalog);
    }

    /**
     * Returns a tree rooted at a boundary or an uncached method whose callees are composed.
     *
     * Expansion is bounded only by the recursion guard, and every boundary is
     * analyzed once. What a command prints is decided afterwards, so no
     * display setting can change what was analyzed.
     *
     * @param list<string> $visited Boundary identifiers already on the current path.
     * @param bool $includeUncached Include ordinary calls beyond the paths needed to report cache propagation gaps.
     */
    public function build(BoundaryDeclaration $boundary, array $visited = [], bool $includeUncached = false): CacheNode
    {
        $id = $boundary->id();

        if (in_array($id, $visited, true)) {
            ++$this->recursions;

            $cause = AnalysisCause::at($boundary, 'recursive-call', 'Recursive dependency was not expanded again');
            $effect = new CacheEffect(TtlEstimate::unknown(condition: 'recursive dependency, not analyzed'), $boundary->scope() ?? Visibility::Shared, visibilityUnknown: $boundary->scope() !== Visibility::NoStore, tagsUnknown: true, analysis: (new MetadataAnalysis())->withCause($cause));

            return new CacheNode($boundary, $effect);
        }

        $key = $id.'#'.($includeUncached ? '1' : '0');

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $before = $this->recursions;
        $node = $this->expand($boundary, [...$visited, $id], $includeUncached);

        if ($this->recursions === $before) {
            $this->memo[$key] = $node;
        }

        return $node;
    }

    /**
     * Expands calls while keeping inspection-only paths out of cache composition.
     *
     * @param list<string> $visited Identifiers on the current path, including this boundary.
     */
    public function expand(BoundaryDeclaration $boundary, array $visited, bool $includeUncached): CacheNode
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

                $child = $this->build($candidate, $visited, $includeUncached);
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
     * Records what a method returns and, when it stores nothing, what it composes.
     *
     * The two answer different questions and never replace each other: the
     * effective result is what a caller observes, while the composed estimate
     * bounds a page built from this method even where an extraction detached
     * that metadata or its propagation was not analyzed.
     *
     * @param list<CacheNode> $children
     * @param list<CacheNode> $constraints
     * @param list<string> $notes
     * @param list<CacheGap> $gaps
     * @param array<string, list<CacheNode>> $calls
     */
    public function finish(BoundaryDeclaration $boundary, array $children, array $constraints, array $notes, array $gaps, array $calls): CacheNode
    {
        $strategy = $boundary->isCacheBoundary ? $this->strategies->resolve($boundary) : null;
        $returned = $boundary->metadataFlow === null ? null : (new FlowEffects($boundary))->evaluate($boundary->metadataFlow, $calls);
        $variants = $returned === null ? null : $this->effects->applyAlternatives(
            $boundary,
            $returned,
            $strategy,
        );

        $effect = $variants !== null && $variants !== []
            ? (count($variants) === 1 ? $variants[0]->effect : (new AlternativeEffects())->summarize($variants))
            : $this->effects->calculate($boundary, $this->effects->constrain($constraints, $gaps !== []), $strategy);

        if ($variants === []) {
            $effect = new CacheEffect(TtlEstimate::unknown(condition: 'method has no normal return'));
        }

        $composed = $boundary->isCacheBoundary || $constraints === []
            ? null
            : $this->effects->compose($this->effects->constrain($constraints, composition: true));
        $local = new CacheNode($boundary, $effect, notes: $notes);
        $diagnostics = $local->diagnostics;

        foreach ($returned ?? [] as $variant) {
            $diagnostics = [...$diagnostics, ...array_filter($variant->effect->analysis->causes(), static fn (AnalysisCause $cause): bool => $cause->method === $boundary->id())];
        }

        return new CacheNode(
            $boundary,
            $effect,
            $children,
            $notes,
            $gaps,
            diagnostics: $diagnostics,
            metadataVariants: $variants,
            calls: CallAnalysis::fromCalls($boundary, $calls),
            composed: $composed,
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

        if (!$boundary->metadataFlow->references($target)) {
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
