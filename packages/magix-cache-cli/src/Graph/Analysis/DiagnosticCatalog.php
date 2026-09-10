<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph\Analysis;

use Magix\Cache\Cli\Graph\CacheNode;

/**
 * Indexes the sources needed to explain a selection of analyzed results.
 */
final readonly class DiagnosticCatalog
{
    /**
     * Collects each cause once, including origins referenced through hidden methods.
     *
     * @param list<CacheNode> $nodes
     * @return array<string, AnalysisCause>
     */
    public function collect(array $nodes): array
    {
        $causes = [];

        foreach ($nodes as $node) {
            $causes = [...$causes, ...$node->diagnostics, ...$node->effect->analysis->causes()];

            foreach ($node->metadataVariants ?? [] as $variant) {
                $causes = [...$causes, ...$variant->effect->analysis->causes()];
            }

            $causes = [...$causes, ...$this->collect($node->children)];
        }

        return $causes;
    }

}
