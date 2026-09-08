<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_merge;
use function implode;

use Magix\Cache\Cli\Graph\CacheNode;

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
        $statements = ['    '.$id.'["'.$label.'"]'];
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
