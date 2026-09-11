<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\Analysis\AnalysisCause;
use Magix\Cache\Cli\Graph\Analysis\CallAnalysis;
use Magix\Cache\Metadata\Visibility;

/**
 * Holds one boundary of a cache tree together with its dependencies.
 */
final readonly class CacheNode
{
    /**
     * @var array<string, AnalysisCause> Local observations, independently of effects inherited by callers.
     */
    public array $diagnostics;

    /**
     * Creates a cache tree node.
     *
     * @param list<CacheNode> $children
     * @param list<string> $notes Observations about how the tree was resolved.
     * @param list<CacheGap> $gaps Cache paths whose metadata propagation is not verified.
     * @param array<string, AnalysisCause>|null $diagnostics Local limitations, including ones whose effects were explicitly replaced.
     * @param list<CacheVariant>|null $metadataVariants Possible returned metadata, kept separate across exclusive paths.
     * @param list<string> $via Original callers omitted from this displayed connection.
     * @param list<CallAnalysis> $calls Original call sites, independently of row filtering.
     * @param CacheEffect|null $composed What the cache boundaries a non-storing method reaches compose to, null for a boundary and for a method that reaches none.
     */
    public function __construct(
        public BoundaryDeclaration $boundary,
        public CacheEffect $effect,
        public array $children = [],
        public array $notes = [],
        public array $gaps = [],
        ?array $diagnostics = null,
        public ?array $metadataVariants = null,
        public array $via = [],
        public array $calls = [],
        public ?CacheEffect $composed = null,
    ) {
        $local = [];

        foreach ($notes as $note) {
            $cause = AnalysisCause::at($boundary, 'call-analysis', $note);
            $local[$cause->id] = $cause;
        }

        $this->diagnostics = $diagnostics ?? [...$local, ...array_filter($effect->analysis->causes(), static fn (AnalysisCause $cause): bool => $cause->method === $boundary->id())];
    }

    /**
     * Returns the constraint this row imposes on everything built from its result.
     *
     * A cache boundary answers with its own stored result: what it decided to
     * store bounds every consumer of that subtree. An ordinary method answers
     * with what the boundaries below it compose to, independently of whether it
     * propagates their metadata to its caller. Extracting a value detaches
     * metadata for the caller, but the data in a page built from it still
     * cannot outlive the caches it came from. A method that reaches no boundary
     * imposes nothing.
     */
    public function composition(): CacheEffect
    {
        return $this->composed ?? ($this->boundary->isCacheBoundary ? $this->effect : new CacheEffect(TtlEstimate::unconstrained()));
    }

    /**
     * Reports storage from the returned result, independently of diagnostic counts.
     */
    public function storage(): string
    {
        if (!$this->boundary->isCacheBoundary || $this->effect->problems !== [] || $this->effect->ttl->state === TtlEstimateState::Invalid
            || $this->effect->visibility === Visibility::NoStore || $this->effect->ttl->seconds === 0) {
            return 'no';
        }

        if ($this->effect->storable) {
            return 'yes';
        }

        return $this->effect->analysis->ttl !== [] || $this->effect->analysis->visibility !== [] ? 'unknown' : 'runtime-dependent';
    }

    /**
     * Projects child rows while retaining every original analysis fact.
     *
     * @param list<CacheNode> $children
     * @param list<string>|null $via Omitted original callers, when promoting this node.
     */
    public function withChildren(array $children, ?array $via = null): self
    {
        return new self($this->boundary, $this->effect, $children, $this->notes, $this->gaps, $this->diagnostics, $this->metadataVariants, $via ?? $this->via, $this->calls, $this->composed);
    }
}
