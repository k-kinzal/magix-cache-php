<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Metadata\Visibility;

/**
 * Gives terminal and diagram output the same meaning independently of storage proof.
 */
final readonly class NodePresentation
{
    /**
     * @return 'white'|'gray'|'yellow'|'red'
     */
    public function color(CacheNode $node): string
    {
        if ($this->invalid($node->effect)) {
            return 'red';
        }

        if (!$node->boundary->isCacheBoundary || $this->disabled($node->effect)) {
            return 'gray';
        }

        return $node->analysisWarnings === [] ? 'white' : 'yellow';
    }

    /**
     * Separates a runtime choice from a proven non-storing result or incomplete analysis.
     */
    public function storage(CacheNode $node): string
    {
        if (!$node->boundary->isCacheBoundary || $this->invalid($node->effect) || $this->disabled($node->effect)) {
            return 'no';
        }

        if ($node->effect->storable) {
            return 'yes';
        }

        return $node->analysisWarnings === [] ? 'runtime-dependent' : 'unknown (analysis incomplete)';
    }

    /**
     * Reports a definition error, never merely missing static storage proof.
     */
    public function invalid(CacheEffect $effect): bool
    {
        return $effect->problems !== [] || $effect->ttl->state === TtlEstimateState::Invalid;
    }

    /**
     * Reports definite non-storage, without treating a possibly zero lifetime as zero.
     */
    public function disabled(CacheEffect $effect): bool
    {
        return $effect->visibility === Visibility::NoStore || $effect->ttl->seconds === 0;
    }

    /**
     * Emits each warning at its lowest visible ancestor, including when its source is hidden.
     *
     * @return list<string>
     */
    public function warnings(CacheNode $node): array
    {
        $below = array_merge(...array_map(static fn (CacheNode $child): array => $child->analysisWarnings, $node->children));

        return array_values(array_diff($node->analysisWarnings, $below));
    }

    /**
     * Keeps diagram colors aligned with terminal rows and field overrides.
     */
    public function mermaid(CacheNode $node): string
    {
        $color = $this->color($node);

        if ($color === 'white' && $node->effect->localOverrides !== []) {
            $color = 'yellow';
        }

        return match ($color) {
            'red' => 'fill:#f8d7da,stroke:#b02a37,color:#842029',
            'gray' => 'fill:#e9ecef,stroke:#868e96,color:#495057',
            'yellow' => 'fill:#fff3cd,stroke:#b58100,color:#664d03',
            'white' => 'fill:#ffffff,stroke:#495057,color:#212529',
        };
    }
}
