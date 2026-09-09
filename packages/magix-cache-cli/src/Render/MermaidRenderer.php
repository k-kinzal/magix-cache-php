<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_merge;
use function implode;

use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\ExpirationEstimate;

/**
 * Renders a cache tree as a Mermaid flowchart for documentation.
 */
final readonly class MermaidRenderer
{
    /**
     * Returns the flowchart for one analyzed boundary.
     */
    public function render(CacheNode $node): string
    {
        return implode("\n", array_merge(['flowchart TD'], $this->statements($node, 'n0')))."\n";
    }

    /**
     * Returns the node and edge statements for one subtree.
     *
     * @return list<string>
     */
    public function statements(CacheNode $node, string $id): array
    {
        $effect = $node->effect;
        $entryPoint = $node->boundary->isCacheBoundary ? '' : ' (uncached entry point)';
        $label = $node->boundary->shortId().$entryPoint.'<br/>'.$effect->ttl->label().' - '.$effect->visibilityLabel();

        if ($id !== 'n0' && !$node->boundary->isCacheBoundary) {
            $label = $node->boundary->shortId().' (uncached)';
        }

        if ($effect->expirationConstraints !== [] && ($id === 'n0' || $node->boundary->isCacheBoundary)) {
            $label .= '<br/>expires by '.ExpirationEstimate::describe($effect->expirationConstraints);
        }

        foreach ($node->gaps as $gap) {
            $label .= '<br/>'.str_replace(' -> ', ' → ', $gap->label());
        }

        $statements = ['    '.$id.'["'.$label.'"]'];

        if ($node->gaps !== []) {
            $statements[] = '    style '.$id.' fill:#f8d7da,stroke:#b02a37,color:#842029';
        } elseif ($effect->localOverrides !== []) {
            $statements[] = '    style '.$id.' fill:#fff3cd,stroke:#b58100,color:#664d03';
        }

        $position = 0;

        foreach ($node->children as $child) {
            $childId = $id.'_'.$position;
            ++$position;
            $statements = array_merge($statements, $this->statements($child, $childId));
            $statements[] = '    '.$id.' --> '.$childId;
        }

        return $statements;
    }
}
