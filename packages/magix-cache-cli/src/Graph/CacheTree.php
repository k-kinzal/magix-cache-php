<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use function count;
use function in_array;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\Catalog;

/**
 * Expands one boundary into the tree of boundaries it depends on.
 */
final readonly class CacheTree
{
    /**
     * Creates a tree builder for one catalog.
     */
    public function __construct(
        private Catalog $catalog,
        private EffectCalculator $effects = new EffectCalculator(),
    ) {
    }

    /**
     * Returns the resolved tree rooted at one boundary.
     *
     * @param list<string> $visited Boundary identifiers already on the current path.
     */
    public function build(BoundaryDeclaration $boundary, int $depth = 8, array $visited = []): CacheNode
    {
        $id = $boundary->id();

        if (in_array($id, $visited, true)) {
            return new CacheNode($boundary, new CacheEffect(visibility: $boundary->scope()), [], ['recursive dependency, not expanded again']);
        }

        if ($depth < 1) {
            $notes = $boundary->dependencies === [] ? [] : ['depth limit reached, dependencies not expanded'];

            return new CacheNode($boundary, $this->effects->calculate($boundary, new DependencyConstraint()), [], $notes);
        }

        $children = [];
        $notes = [];
        $seen = [];

        foreach ($boundary->dependencies as $dependency) {
            $candidates = $this->catalog->candidates($dependency->class, $dependency->method);

            if (count($candidates) > 1) {
                $notes[] = $dependency->class.'::'.$dependency->method.' resolves to '.count($candidates).' implementations';
            }

            foreach ($candidates as $candidate) {
                if (isset($seen[$candidate->id()])) {
                    continue;
                }

                $seen[$candidate->id()] = true;
                $children[] = $this->build($candidate, $depth - 1, [...$visited, $id]);
            }
        }

        return new CacheNode(
            $boundary,
            $this->effects->calculate($boundary, $this->effects->constrain($children)),
            $children,
            $notes,
        );
    }
}
