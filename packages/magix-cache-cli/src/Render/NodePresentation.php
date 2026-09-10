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
     * @return 'white'|'gray'
     */
    public function color(CacheNode $node): string
    {
        return $node->boundary->policy === null || ($node->boundary->isCacheBoundary && $this->disabled($node->effect)) ? 'gray' : 'white';
    }

    /**
     * Separates a runtime choice from a proven non-storing result or incomplete analysis.
     */
    public function storage(CacheNode $node): string
    {
        return $node->storage();
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
     * Keeps diagram row colors aligned with the terminal overview.
     */
    public function mermaid(CacheNode $node): string
    {
        $color = $this->color($node);

        return match ($color) {
            'gray' => 'fill:#e9ecef,stroke:#868e96,color:#495057',
            'white' => 'fill:#ffffff,stroke:#495057,color:#212529',
        };
    }
}
