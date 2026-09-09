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

        foreach ($node->effect->problems as $problem) {
            $label .= '<br/>! '.$problem;
        }

        $presentation = new NodePresentation();
        $alternatives = (new AlternativePresentation())->label($node);

        if ($alternatives !== null) {
            $label .= '<br/>alternatives: '.$alternatives;
        }

        foreach ($presentation->warnings($node) as $warning) {
            $label .= '<br/>~ '.str_replace(' -> ', ' → ', $warning);
        }

        $statements = ['    '.$id.'["'.$label.'"]', '    style '.$id.' '.$presentation->mermaid($node)];

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
