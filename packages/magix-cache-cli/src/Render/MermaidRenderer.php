<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_merge;
use function implode;

use Magix\Cache\Cli\Graph\CacheNode;

use function strtr;

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

        if ($node->boundary->comment !== null && $node->boundary->comment !== '') {
            $label .= '<br/>comment: '.$this->comment($node->boundary->comment);
        }

        $statements = ['    '.$id.'["'.$label.'"]'];

        if ($effect->localRestrictions !== []) {
            $fields = implode(', ', array_keys($effect->localRestrictions));
            $statements[0] = '    '.$id.'["'.$label.'<br/>local restriction: '.$fields.'"]';
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

    /**
     * Escapes comment text as Mermaid entities, retaining line breaks in the label.
     */
    public function comment(string $comment): string
    {
        return strtr($comment, [
            '#' => '#35;',
            '&' => '#38;',
            '"' => '#quot;',
            '<' => '#lt;',
            '>' => '#gt;',
            "\r\n" => '<br/>',
            "\r" => '<br/>',
            "\n" => '<br/>',
        ]);
    }
}
