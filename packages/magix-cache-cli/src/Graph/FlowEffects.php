<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Graph;

use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\MetadataFlow;
use Magix\Cache\Cli\Graph\Analysis\AnalysisCause;
use Magix\Cache\Cli\Graph\Analysis\MetadataAnalysis;
use Magix\Cache\Cli\Graph\Analysis\MetadataReference;
use Magix\Cache\Cli\Graph\Analysis\TtlReference;

/**
 * Evaluates return paths: choices union candidates, composition meets their products.
 */
final readonly class FlowEffects
{
    /**
     * Attributes limitations to the method whose returned expression is evaluated.
     */
    public function __construct(private ?BoundaryDeclaration $owner = null)
    {
    }

    /**
     * @param array<string, list<CacheNode>> $calls
     * @return list<CacheVariant>
     */
    public function evaluate(MetadataFlow $flow, array $calls): array
    {
        if ($flow->kind === 'call') {
            if (($calls[$flow->target ?? ''] ?? []) === []) {
                return [$this->unknown($flow, 'unresolved-return-call')];
            }

            return array_map(
                static fn (CacheVariant $variant, int $index): CacheVariant => $variant->select('call:'.spl_object_id($flow), $index, replace: true),
                $variants = $this->call($calls[$flow->target ?? '']),
                array_keys($variants),
            );
        }

        if ($flow->kind === 'unknown') {
            $inputs = array_merge(...array_map(fn (MetadataFlow $input): array => $this->evaluate($input, $calls), $flow->inputs));

            return [$this->unknown($flow, $flow->reason ?? 'opaque-return', $inputs)];
        }

        if ($flow->kind === 'collection') {
            return [new CacheVariant(new CacheEffect(TtlEstimate::unconstrained()))];
        }

        if ($flow->kind === 'wrap' || $flow->kind === 'preserve') {
            return $this->carried($this->evaluate($flow->inputs[0], $calls), $flow->kind === 'preserve');
        }

        if ($flow->kind === 'value') {
            return $this->detached($this->evaluate($flow->inputs[0], $calls));
        }

        $variants = $flow->kind === 'choice' ? [] : [new CacheVariant(new CacheEffect(TtlEstimate::unconstrained()))];

        foreach ($flow->inputs as $index => $input) {
            $next = $this->evaluate($input, $calls);
            $variants = $flow->kind === 'choice'
                ? [...$variants, ...array_map(static fn (CacheVariant $variant): CacheVariant => $variant->select('choice:'.spl_object_id($flow), $index), $next)]
                : $this->product($variants, $next);

            if (count($variants) > 128) {
                return [$this->unknown($flow, 'alternative-limit', $variants)];
            }
        }

        return $this->unique($variants);
    }

    /**
     * Marks a value as carrying metadata, keeping every other field as it was.
     *
     * @param list<CacheVariant> $variants
     * @param bool $requiresCarrier Whether the operation only works on a value that already carries metadata.
     * @return list<CacheVariant>
     */
    public function carried(array $variants, bool $requiresCarrier): array
    {
        return array_map(fn (CacheVariant $variant): CacheVariant => $requiresCarrier && !$variant->cached ? $this->unknown() : new CacheVariant(
            $variant->effect,
            $variant->sources,
            $variant->analyzed,
            $variant->ttlSource,
            $variant->visibilitySource,
            $variant->selections,
            cached: true,
        ), $variants);
    }

    /**
     * Takes a value out of its carrier, which leaves its constraints behind.
     *
     * @param list<CacheVariant> $receivers
     * @return list<CacheVariant>
     */
    public function detached(array $receivers): array
    {
        return $this->unique(array_map(fn (CacheVariant $receiver): CacheVariant => !$receiver->cached
            ? $this->unknown()
            : new CacheVariant(new CacheEffect(TtlEstimate::unconstrained()), selections: $receiver->selections), $receivers));
    }

    /**
     * Multiple implementations are alternatives, never simultaneous dependencies.
     *
     * Following a resolved call is itself an analyzed step: the callee's own
     * limits travel in the metadata it hands back, and the note describing
     * them belongs to the callee, not to every caller above it.
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
            $returns = $node->metadataVariants ?? [new CacheVariant($node->effect)];

            foreach ($returns as $variant) {
                $variants[] = new CacheVariant(
                    $variant->effect,
                    $node->boundary->isCacheBoundary ? [$node->boundary->shortId(), ...(count($returns) > 1 ? $variant->sources : [])] : $variant->sources,
                    true,
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
                    return [$this->unknown(kind: 'alternative-limit', inputs: [...$first, ...$second])];
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
                        analysis: $a->analysis->merge($b->analysis),
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
     *
     * @param list<CacheVariant> $inputs Understood operands, used only for display references.
     */
    public function unknown(?MetadataFlow $flow = null, string $kind = 'opaque-return', array $inputs = []): CacheVariant
    {
        $message = match ($kind) {
            'unresolved-return-call' => 'The returned call could not be resolved: '.($flow->target ?? 'unknown target'),
            'alternative-limit' => 'Return alternatives exceeded the analysis budget',
            default => 'Returned metadata could not be followed through '.$kind,
        };
        $cause = AnalysisCause::at($this->owner, $kind, $message, $flow->line ?? 0);

        return new CacheVariant(new CacheEffect(
            TtlEstimate::unknown(condition: 'returned cache metadata is not analyzed'),
            visibilityUnknown: true,
            tagsUnknown: true,
            analysis: (new MetadataAnalysis(
                ttlReference: TtlReference::fromVariants($inputs),
                visibilityReference: MetadataReference::fromVisibility($inputs),
                tagsReference: MetadataReference::fromTags($inputs),
            ))->withCause($cause),
        ), analyzed: false);
    }
}
