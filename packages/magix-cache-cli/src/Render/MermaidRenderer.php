<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\ExpirationEstimate;

/**
 * Renders the same compact overview as the terminal, preserving promoted connections.
 */
final readonly class MermaidRenderer
{
    /**
     * Returns one diagram for the projected tree.
     */
    public function render(CacheNode $node): string
    {
        return $this->forest([$node]);
    }

    /**
     * @param list<CacheNode> $nodes
     */
    public function forest(array $nodes): string
    {
        $lines = ['flowchart TD'];

        foreach ($nodes as $index => $node) {
            $lines = [...$lines, ...$this->statements($node, 'n'.$index)];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    public function statements(CacheNode $node, string $id): array
    {
        $values = new ValuePresentation();
        $escape = static fn (string $label): string => str_replace(['&', '"', '<', '>', "\n", "\r"], ['&amp;', '&quot;', '&lt;', '&gt;', ' ', ' '], $label);
        $label = $escape($node->boundary->shortId());

        if ($node->boundary->policy !== null || $node->boundary->isCacheBoundary) {
            $label .= '<br/>'.$escape($values->ttl($node));

            if ($node->boundary->isCacheBoundary) {
                $label .= ' - '.$escape($values->visibility($node->effect));

                if ($node->effect->tags !== [] || $node->effect->tagsUnknown) {
                    $label .= ' - tags '.$escape($values->tags($node->effect));
                }
            }

            if ($node->effect->expirationConstraints !== []) {
                $label .= '<br/>expires by '.$escape(ExpirationEstimate::describe($node->effect->expirationConstraints));
            }
        } else {
            $label .= ' (uncached)';
        }

        if ($node->effect->problems !== []) {
            $label .= '<br/>[declaration problem]';
        }

        $statements = ['    '.$id.'["'.$label.'"]', '    style '.$id.' '.(new NodePresentation())->mermaid($node)];

        foreach ($node->children as $position => $child) {
            $childId = $id.'_'.$position;
            $statements = [...$statements, ...$this->statements($child, $childId), '    '.$id.($child->via === [] ? ' --> ' : ' -.-> ').$childId];
        }

        return $statements;
    }

}
