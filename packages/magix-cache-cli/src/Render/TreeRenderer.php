<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_merge;
use function count;
use function implode;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;

use function strtolower;

/**
 * Renders one cache tree as an indented terminal report.
 */
final readonly class TreeRenderer
{
    /**
     * Returns the full report for one analyzed boundary.
     */
    public function render(CacheNode $node): string
    {
        $effect = $node->effect;
        $lines = [
            '<options=bold>'.$node->boundary->id().'</>',
            '  '.$node->boundary->file.':'.$node->boundary->line,
            '',
            '  ttl          '.$this->ttl($effect),
            '  visibility   '.strtolower($effect->visibility->name)
                .($effect->visibilityReason === null ? '' : ' ('.$effect->visibilityReason.')'),
            '  storable     '.($effect->storable ? 'yes' : 'no'),
            '  tags         '.($effect->tags === [] ? '-' : implode(', ', $effect->tags)),
            '  key          '.$this->key($node->boundary),
            '  policy       '.($node->boundary->policy?->label() ?? 'not declared'),
            '',
        ];

        return implode("\n", array_merge($lines, $this->lines($node)))."\n";
    }

    /**
     * Returns the tree lines for one node and everything below it.
     *
     * @param bool|null $last Null for the root, true for the last child of a parent.
     * @return list<string>
     */
    public function lines(CacheNode $node, string $prefix = '', ?bool $last = null): array
    {
        $connector = $last === null ? '' : ($last ? '`-- ' : '|-- ');
        $indent = $last === null ? $prefix : $prefix.($last ? '    ' : '|   ');
        $lines = [$prefix.$connector.$this->summary($node)];

        foreach ($node->effect->problems as $problem) {
            $lines[] = $indent.'    <fg=red>! '.$problem.'</>';
        }

        foreach ($node->notes as $note) {
            $lines[] = $indent.'    <fg=yellow>~ '.$note.'</>';
        }

        $remaining = count($node->children);

        foreach ($node->children as $child) {
            --$remaining;
            $lines = array_merge($lines, $this->lines($child, $indent, $remaining === 0));
        }

        return $lines;
    }

    /**
     * Returns the single line that describes one boundary in the tree.
     */
    public function summary(CacheNode $node): string
    {
        $effect = $node->effect;
        $declared = $node->boundary->policy;
        $ttl = 'ttl '.($effect->ttl === null ? 'none' : '<fg=green>'.$effect->ttl.'s</>');

        if ($declared !== null && $declared->ttlLabel() !== ($effect->ttl === null ? '' : $effect->ttl.'s')) {
            $ttl .= ' (declared '.$declared->ttlLabel().')';
        }

        $parts = [
            '<options=bold>'.$node->boundary->shortId().'</>',
            $ttl,
            strtolower($effect->visibility->name),
        ];

        if ($effect->tags !== []) {
            $parts[] = 'tags '.implode(',', $effect->tags);
        }

        return implode('  ', $parts);
    }

    /**
     * Returns the effective lifetime with the reason it was chosen.
     */
    public function ttl(CacheEffect $effect): string
    {
        $ttl = $effect->ttl === null ? 'none' : '<fg=green>'.$effect->ttl.'s</>';

        return $effect->ttlReason === null ? $ttl : $ttl.' ('.$effect->ttlReason.')';
    }

    /**
     * Returns the values that make up the cache key of one boundary.
     */
    public function key(BoundaryDeclaration $boundary): string
    {
        $keyed = [];
        $ignored = [];

        foreach ($boundary->parameters as $parameter) {
            if ($parameter->ignored) {
                $ignored[] = '$'.$parameter->name;

                continue;
            }

            $keyed[] = $parameter->label();
        }

        $key = $keyed === [] ? 'class, method and version only' : implode(', ', $keyed);

        if ($ignored !== []) {
            $key .= ' (ignored: '.implode(', ', $ignored).')';
        }

        return $key.'  version '.($boundary->policy->version ?? '1');
    }
}
