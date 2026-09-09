<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_merge;
use function count;
use function implode;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Graph\CacheEffect;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\ExpirationEstimate;
use Magix\Cache\Cli\Graph\TtlEstimate;
use Magix\Cache\Cli\Graph\TtlEstimateState;
use Magix\Cache\Runtime\Policy\Ttl;

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
            $this->highlight($node->boundary->id(), $node, bold: true),
            '  '.$node->boundary->file.':'.$node->boundary->line,
            '',
            ...$this->strategy($effect),
            '  ttl          '.$this->ttl($effect),
            ...($effect->expirationConstraints === [] ? [] : ['  expires by   '.ExpirationEstimate::describe($effect->expirationConstraints)]),
            '  visibility   '.$this->restricted($effect->visibilityLabel(), $effect, 'visibility')
                .($effect->visibilityReason === null ? '' : ' ('.$effect->visibilityReason.')'),
            '  storable     '.($effect->storable ? 'yes' : 'no'),
            '  tags         '.$this->restricted($effect->tagsLabel(), $effect, 'tags'),
            '  key          '.$this->key($node->boundary),
            '  policy       '.($node->boundary->isCacheBoundary ? ($node->boundary->policy?->label() ?? 'not declared') : 'none (uncached entry point)'),
            '',
        ];

        return $this->highlight(implode("\n", $lines), $node)."\n".implode("\n", $this->lines($node))."\n";
    }

    /**
     * Returns the rows describing the declared strategy composition.
     *
     * The candidate constraint the strategies choose and the effective
     * lifetime that survives composition are two different rows on purpose.
     *
     * @return list<string>
     */
    public function strategy(CacheEffect $effect): array
    {
        $strategy = $effect->strategy;

        if ($strategy === null) {
            return [];
        }

        $lines = ['  strategy     '.$strategy->label];

        foreach ($strategy->steps as $step) {
            $assumed = $step->assumed ? ' (assumed)' : '';
            $candidate = $step->expirations === [] ? $this->labelled($step->ttl) : 'expires '.ExpirationEstimate::describe($step->expirations);
            $lines[] = '               - '.$step->shortName().'  '.$candidate.$assumed;
        }

        $lines[] = '  strategy ttl '.$this->labelled($strategy->ttl);

        if ($strategy->expirations !== []) {
            $lines[] = '  strategy at  '.ExpirationEstimate::describe($strategy->expirations);
        }

        return $lines;
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
        $lines = [$prefix.$connector.$this->summary($node, $last === null)];

        foreach ($node->gaps as $gap) {
            $lines[] = $indent.'    <fg=red>! '.$gap->label().'</>';
        }

        foreach ($node->effect->problems as $problem) {
            $lines[] = $indent.'    <fg=red>! '.$problem.'</>';
        }

        foreach ($node->notes as $note) {
            $lines[] = $indent.'    <fg=gray>~ '.$note.'</>';
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
     *
     * @param bool $root Summarize called caches for an uncached root; label ordinary descendants without suggesting their own cache policy.
     */
    public function summary(CacheNode $node, bool $root = true): string
    {
        if (!$root && !$node->boundary->isCacheBoundary) {
            return $this->highlight($this->highlight($node->boundary->shortId(), $node, bold: true).' (uncached)', $node);
        }

        $effect = $node->effect;
        $declared = $node->boundary->policy;
        $ttl = 'ttl '.$this->restricted($this->estimate($effect->ttl), $effect, 'ttl');

        if ($declared !== null && $declared->ttl !== Ttl::Auto && $declared->ttlLabel() !== $effect->ttl->label()) {
            $ttl .= ' (declared '.$declared->ttlLabel().')';
        }

        $parts = [
            $this->highlight($node->boundary->shortId(), $node, bold: true).($node->boundary->isCacheBoundary ? '' : ' (uncached entry point)'),
            $ttl,
            $this->restricted($effect->visibilityLabel(), $effect, 'visibility'),
        ];

        if ($effect->expirationConstraints !== []) {
            $parts[] = 'expires by '.ExpirationEstimate::describe($effect->expirationConstraints);
        }

        if ($effect->tags !== [] || $effect->tagsUnknown) {
            $parts[] = 'tags '.$this->restricted($effect->tagsLabel(','), $effect, 'tags');
        }

        return $this->highlight(implode('  ', $parts), $node);
    }

    /**
     * Uses white for provably storable boundaries and gray for every other node.
     *
     * Runtime-dependent effects remain gray because storage is not proven.
     * Nested bold labels repeat the foreground because Console styles do not inherit it.
     */
    public function highlight(string $label, CacheNode $node, bool $bold = false): string
    {
        $color = $node->effect->storable ? 'white' : 'gray';

        return '<fg='.$color.($bold ? ';options=bold' : '').'>'.$label.'</>';
    }

    /**
     * Returns the effective lifetime with the reason or condition behind it.
     */
    public function ttl(CacheEffect $effect): string
    {
        $ttl = $this->restricted($this->estimate($effect->ttl), $effect, 'ttl');

        return $effect->ttl->reason === null ? $ttl : $ttl.' ('.$effect->ttl->reason.')';
    }

    /**
     * Highlights a explicitly overridden field only while the result remains storable.
     *
     * @param 'ttl'|'visibility'|'tags' $field
     */
    public function restricted(string $label, CacheEffect $effect, string $field): string
    {
        if (!$effect->storable || !isset($effect->localOverrides[$field])) {
            return $label;
        }

        $label = $field === 'ttl' ? $effect->ttl->label() : $label;

        return '<fg=yellow>'.$label.'</>';
    }

    /**
     * Returns one estimate with the reason or condition behind it.
     */
    public function labelled(TtlEstimate $estimate): string
    {
        $ttl = $this->estimate($estimate);

        return $estimate->reason === null ? $ttl : $ttl.' ('.$estimate->reason.')';
    }

    /**
     * Returns one lifetime estimate rendered for the terminal.
     */
    public function estimate(TtlEstimate $estimate): string
    {
        return match ($estimate->state) {
            TtlEstimateState::Invalid => '<fg=red>invalid</>',
            default => $estimate->label(),
        };
    }

    /**
     * Returns the values that make up the cache key of one boundary.
     */
    public function key(BoundaryDeclaration $boundary): string
    {
        if (!$boundary->isCacheBoundary) {
            return 'none (uncached entry point)';
        }

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
