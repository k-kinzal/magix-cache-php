<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\MetadataFlow;

/**
 * Evaluates return paths: choices union candidates, composition meets their products.
 */
final readonly class FlowEffects
{
    /**
     * @param array<string, list<CacheNode>> $calls
     * @return list<CacheVariant>
     */
    public function evaluate(MetadataFlow $flow, array $calls): array
    {
        if ($flow->kind === 'call') {
            return array_map(
                static fn (CacheVariant $variant, int $index): CacheVariant => $variant->select('call:'.spl_object_id($flow), $index, replace: true),
                $variants = $this->call($calls[$flow->target ?? ''] ?? []),
                array_keys($variants),
            );
        }

        if ($flow->kind === 'unknown') {
            return [$this->unknown()];
        }

        if ($flow->kind === 'wrap' || $flow->kind === 'preserve') {
            return array_map(fn (CacheVariant $variant): CacheVariant => $flow->kind === 'preserve' && !$variant->cached ? $this->unknown() : new CacheVariant(
                $variant->effect,
                $variant->sources,
                $variant->analyzed,
                $variant->ttlSource,
                $variant->visibilitySource,
                $variant->selections,
                cached: true,
            ), $this->evaluate($flow->inputs[0], $calls));
        }

        if ($flow->kind === 'value') {
            $receivers = $this->evaluate($flow->inputs[0], $calls);

            return $this->unique(array_map(fn (CacheVariant $receiver): CacheVariant => !$receiver->cached
                ? $this->unknown() : new CacheVariant(new CacheEffect(TtlEstimate::unconstrained()), selections: $receiver->selections), $receivers));
        }

        $variants = $flow->kind === 'choice' ? [] : [new CacheVariant(new CacheEffect(TtlEstimate::unconstrained()))];

        foreach ($flow->inputs as $index => $input) {
            $next = $this->evaluate($input, $calls);
            $variants = $flow->kind === 'choice'
                ? [...$variants, ...array_map(static fn (CacheVariant $variant): CacheVariant => $variant->select('choice:'.spl_object_id($flow), $index), $next)]
                : $this->product($variants, $next);

            if (count($variants) > 128) {
                return [$this->unknown()];
            }
        }

        return $this->unique($variants);
    }

    /**
     * Multiple implementations are alternatives, never simultaneous dependencies.
     *
     * @param list<CacheNode> $nodes
     * @return list<CacheVariant>
     */
    public function call(array $nodes): array
    {
        if ($nodes === []) {
            return [$this->unknown()];
        }

        $variants = [];

        foreach ($nodes as $node) {
            $returns = $node->metadataVariants ?? [new CacheVariant($node->effect, analyzed: $node->boundary->isCacheBoundary && $node->notes === [])];

            foreach ($returns as $variant) {
                $variants[] = new CacheVariant(
                    $variant->effect,
                    $node->boundary->isCacheBoundary ? [$node->boundary->shortId(), ...(count($returns) > 1 ? $variant->sources : [])] : $variant->sources,
                    $variant->analyzed,
                    $node->boundary->shortId(),
                    $node->boundary->shortId(),
                    cached: $node->boundary->isCacheBoundary || $variant->cached,
                );
            }
        }

        return $this->unique($variants);
    }

    /**
     * @param list<CacheVariant> $first
     * @param list<CacheVariant> $second
     * @return list<CacheVariant>
     */
    public function product(array $first, array $second): array
    {
        $variants = [];

        foreach ($first as $left) {
            foreach ($second as $right) {
                if (!$left->compatible($right)) {
                    continue;
                }

                if (count($variants) >= 128) {
                    return [$this->unknown()];
                }

                $a = $left->effect;
                $b = $right->effect;
                $variants[] = new CacheVariant(
                    new CacheEffect(
                        ttl: $a->ttl->meet($b->ttl),
                        visibility: $a->visibility->meet($b->visibility),
                        tags: (new EffectCalculator())->tags([...$a->tags, ...$b->tags]),
                        problems: array_values(array_unique([...$a->problems, ...$b->problems])),
                        visibilityUnknown: $a->visibilityUnknown || $b->visibilityUnknown,
                        tagsUnknown: $a->tagsUnknown || $b->tagsUnknown,
                        expirationConstraints: [...$a->expirationConstraints, ...$b->expirationConstraints],
                    ),
                    array_values(array_unique([...$left->sources, ...$right->sources])),
                    $left->analyzed && $right->analyzed,
                    $a->ttl->meet($b->ttl)->equals($a->ttl) ? $left->ttlSource : $right->ttlSource,
                    $a->visibility->meet($b->visibility) === $a->visibility ? $left->visibilitySource : $right->visibilitySource,
                    [...$left->selections, ...$right->selections],
                    $left->cached || $right->cached,
                );
            }
        }

        return $this->unique($variants);
    }

    /**
     * @param list<CacheVariant> $variants
     * @return list<CacheVariant>
     */
    public function unique(array $variants): array
    {
        $unique = [];

        foreach ($variants as $variant) {
            foreach ($unique as $existing) {
                if ($existing->equals($variant)) {
                    continue 2;
                }
            }

            $unique[] = $variant;
        }

        return $unique;
    }

    /**
     * Unknown paths keep every metadata field open rather than borrowing a child's value.
     */
    public function unknown(): CacheVariant
    {
        return new CacheVariant(new CacheEffect(
            TtlEstimate::unknown(condition: 'returned cache metadata is not analyzed'),
            visibilityUnknown: true,
            tagsUnknown: true,
        ), analyzed: false);
    }
}
