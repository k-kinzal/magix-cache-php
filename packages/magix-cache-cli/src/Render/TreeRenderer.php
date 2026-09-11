<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\ExpirationEstimate;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders a structural overview with exactly one line per visible method.
 */
final readonly class TreeRenderer
{
    /**
     * Returns the projected tree; detailed declarations and causes belong to JSON.
     */
    public function render(CacheNode $node): string
    {
        return implode("\n", $this->lines($node))."\n";
    }

    /**
     * @param bool|null $last Null identifies the root of this projected tree.
     * @return list<string>
     */
    public function lines(CacheNode $node, string $prefix = '', ?bool $last = null): array
    {
        $connector = $last === null ? '' : ($last ? '`-- ' : '|-- ');
        $indent = $last === null ? $prefix : $prefix.($last ? '    ' : '|   ');
        $lines = [$prefix.$connector.$this->summary($node)];
        $remaining = count($node->children);

        foreach ($node->children as $child) {
            --$remaining;
            $lines = [...$lines, ...$this->lines($child, $indent, $remaining === 0)];
        }

        return $lines;
    }

    /**
     * Uses concise field markers while preserving the known structure and values.
     */
    public function summary(CacheNode $node): string
    {
        $name = $this->highlight(OutputFormatter::escape($node->boundary->shortId()), $node, true);

        if ($node->boundary->policy === null && !$node->boundary->isCacheBoundary) {
            return $this->highlight(implode('  ', [$name.' (uncached)', ...$this->composes($node)]), $node);
        }

        $values = new ValuePresentation();
        $effect = $node->effect;
        $parts = [$name, 'ttl '.$this->field($values->ttl($node), $effect, 'ttl')];

        if ($node->boundary->isCacheBoundary) {
            $parts[] = $this->field($values->visibility($effect), $effect, 'visibility');

            if ($effect->tags !== [] || $effect->tagsUnknown) {
                $parts[] = 'tags '.$this->field($values->tags($effect), $effect, 'tags');
            }

            if ($effect->expirationConstraints !== []) {
                $parts[] = 'expires by '.OutputFormatter::escape(ExpirationEstimate::describe($effect->expirationConstraints));
            }
        }

        $parts = [...$parts, ...$this->composes($node)];

        if ($effect->problems !== []) {
            $parts[] = '<fg=red>[declaration problem]</>';
        }

        return $this->highlight(implode('  ', $parts), $node);
    }

    /**
     * Reports what a method that stores nothing still bounds its result by.
     *
     * This is the page-level estimate: the caches the method reaches, met the
     * way dependencies bubble. It is not storage proof and not the metadata the
     * method hands to its caller, which an extraction detaches.
     *
     * @return list<string>
     */
    public function composes(CacheNode $node): array
    {
        $effect = $node->composed;

        if ($effect === null) {
            return [];
        }

        $values = new ValuePresentation();
        $parts = ['composes ttl '.OutputFormatter::escape($values->lifetime($effect)), OutputFormatter::escape($values->visibility($effect))];

        if ($effect->tags !== [] || $effect->tagsUnknown) {
            $parts[] = 'tags '.OutputFormatter::escape($values->tags($effect));
        }

        if ($effect->expirationConstraints !== []) {
            $parts[] = 'expires by '.OutputFormatter::escape(ExpirationEstimate::describe($effect->expirationConstraints));
        }

        return $parts;
    }

    /**
     * Highlights field replacements without recoloring unrelated ancestors.
     */
    public function field(string $label, CacheEffect $effect, string $field): string
    {
        $label = OutputFormatter::escape($label);

        if ($field === 'ttl' && $effect->ttl->state->value === 'invalid') {
            return '<fg=red>'.$label.'</>';
        }

        return isset($effect->localOverrides[$field]) && !(new NodePresentation())->disabled($effect) ? '<fg=yellow>'.$label.'</>' : $label;
    }

    /**
     * Colors cache declarations independently of local or descendant analysis limits.
     */
    public function highlight(string $label, CacheNode $node, bool $bold = false): string
    {
        return '<fg='.(new NodePresentation())->color($node).($bold ? ';options=bold' : '').'>'.$label.'</>';
    }
}
