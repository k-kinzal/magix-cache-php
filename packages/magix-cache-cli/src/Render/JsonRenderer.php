<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_map;
use function json_encode;

use JsonException;
use Magix\Cache\Cli\Declaration\BoundaryDeclaration;
use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Graph\Analysis\DiagnosticCatalog;
use Magix\Cache\Cli\Graph\CacheGap;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\StrategyEffect;
use Magix\Cache\Cli\Graph\StrategyStep;

use function strtolower;

/**
 * Renders cache trees as JSON for editors and other tools.
 */
final readonly class JsonRenderer
{
    /**
     * Returns one or more cache trees encoded as JSON.
     *
     * @param list<CacheNode> $nodes
     * @throws JsonException
     */
    public function render(array $nodes): string
    {
        $trees = array_map($this->tree(...), $nodes);

        return json_encode(
            ['roots' => $trees, 'diagnostics' => array_values((new DiagnosticCatalog())->collect($nodes))],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Returns one cache tree as plain data.
     *
     * @param bool $root Distinguish an uncached entry point from an ordinary callee in the kind field.
     * @return array<string, mixed>
     */
    public function tree(CacheNode $node, bool $root = true): array
    {
        $boundary = $node->boundary;
        $effect = $node->effect;
        $policy = $boundary->policy;

        return [
            'boundary' => $boundary->id(),
            'kind' => $boundary->isCacheBoundary ? 'boundary' : ($root ? 'entry-point' : 'uncached'),
            'declared' => $boundary->policy !== null,
            'execution' => $boundary->isCacheBoundary ? 'observed' : 'not-observed',
            'returnType' => $boundary->returnType,
            'parameters' => $boundary->parameters,
            'hasDynamicTtl' => $boundary->hasDynamicTtl,
            'useStrategy' => $boundary->useStrategy === null ? null : [
                'strategy' => $boundary->useStrategy->strategy,
                'line' => $boundary->useStrategy->line,
                'arguments' => array_map((new ArgumentPresentation())->data(...), $boundary->useStrategy->arguments),
            ],
            'via' => $node->via,
            'calls' => $node->calls,
            'file' => $boundary->file,
            'line' => $boundary->line,
            'policy' => $policy === null ? null : $this->policy($policy),
            'key' => !$boundary->isCacheBoundary ? null : array_map(
                static fn ($parameter): array => [
                    'name' => $parameter->name,
                    'type' => $parameter->type,
                    'ignored' => $parameter->ignored,
                    'scope' => $parameter->scope === null ? null : strtolower($parameter->scope->name),
                    'reducer' => $parameter->reducer,
                    'configuration' => $parameter->configuration,
                ],
                $boundary->parameters,
            ),
            'strategy' => $effect->strategy === null ? null : $this->strategy($effect->strategy),
            'effective' => $this->effect($node),
            'composed' => $this->composed($node),
            'notes' => $node->notes,
            ...($node->metadataVariants === null ? [] : ['metadataAlternatives' => array_map((new AlternativePresentation())->data(...), $node->metadataVariants)]),
            'diagnostics' => array_keys($node->diagnostics),
            'analysisGaps' => array_map($this->gap(...), $node->gaps),
            'dependencies' => array_map(fn (CacheNode $child): array => $this->tree($child, false), $node->children),
        ];
    }

    /**
     * Serializes result certainty separately from source causes and storage proof.
     *
     * @return array<string, mixed>
     */
    public function effect(CacheNode $node): array
    {
        $effect = $node->effect;

        return [
            'ttl' => $effect->ttl->jsonSerialize(),
            ...($effect->expirationConstraints === [] ? [] : ['expirationConstraints' => $effect->expirationConstraints]),
            'visibility' => strtolower($effect->visibility->name),
            'visibilityReason' => $effect->visibilityReason,
            'visibilityUnknown' => $effect->visibilityUnknown,
            'tagsUnknown' => $effect->tagsUnknown,
            'storable' => $effect->storable,
            'tags' => $effect->tags,
            'problems' => $effect->problems,
            'certainty' => $effect->certainty(),
            'analysis' => $effect->analysis,
            'storage' => $node->storage(),
            'localOverrides' => $effect->localOverrides,
        ];
    }

    /**
     * Serializes the page-level estimate of a method that stores nothing itself.
     *
     * The composed constraint answers what a result built from this method is
     * bounded by, so it carries no storable flag or storage label: reaching a
     * cache is not proof that this method stores one.
     *
     * @return array<string, mixed>|null
     */
    public function composed(CacheNode $node): ?array
    {
        $effect = $node->composed;

        if ($effect === null) {
            return null;
        }

        return [
            'ttl' => $effect->ttl->jsonSerialize(),
            ...($effect->expirationConstraints === [] ? [] : ['expirationConstraints' => $effect->expirationConstraints]),
            'visibility' => strtolower($effect->visibility->name),
            'visibilityReason' => $effect->visibilityReason,
            'visibilityUnknown' => $effect->visibilityUnknown,
            'tagsUnknown' => $effect->tagsUnknown,
            'tags' => $effect->tags,
            'certainty' => $effect->certainty(),
        ];
    }

    /**
     * Returns one declared policy as plain data.
     *
     * A field the source declared but the reader could not resolve is null
     * with its own flag, never the value the runtime would have defaulted to.
     *
     * @return array<string, mixed>
     */
    public function policy(PolicyDeclaration $policy): array
    {
        return [
            'source' => $policy->source->name,
            'ttl' => $policy->ttlLabel(),
            'ttlUnknown' => $policy->ttl === null,
            'tagsUnknown' => $policy->tagsUnknown,
            'visibilityUnknown' => $policy->visibilityUnknown,
            'maxTtl' => $policy->maxTtl,
            'maxTtlUnknown' => $policy->maxTtlUnknown,
            'tags' => $policy->tags,
            'visibility' => $policy->visibility === null ? null : strtolower($policy->visibility->name),
            'version' => $policy->versionUnknown ? null : $policy->version,
            'runtime' => $policy->runtimeUnknown ? null : $policy->runtime,
        ];
    }

    /**
     * Returns an analysis gap with a stable kind and fully qualified call path.
     *
     * @return array{kind: string, path: list<string>, message: string}
     */
    public function gap(CacheGap $gap): array
    {
        return [
            'kind' => 'unverified-cache-propagation',
            'path' => array_map(static fn (BoundaryDeclaration $boundary): string => $boundary->id(), $gap->path),
            'message' => $gap->label(),
        ];
    }

    /**
     * Returns one analyzed strategy composition as plain data.
     *
     * @return array<string, mixed>
     */
    public function strategy(StrategyEffect $strategy): array
    {
        return [
            'declared' => $strategy->label,
            'ttl' => $strategy->ttl->jsonSerialize(),
            'overridesExpiration' => $strategy->overridesExpiration,
            ...($strategy->expirations === [] ? [] : ['expirations' => $strategy->expirations]),
            'steps' => array_map(
                static fn (StrategyStep $step): array => [
                    'strategy' => $step->strategy,
                    'ttl' => $step->ttl->jsonSerialize(),
                    'assumed' => $step->assumed,
                    ...($step->expirations === [] ? [] : ['expirations' => $step->expirations]),
                ],
                $strategy->steps,
            ),
            'writesMetadata' => $strategy->writes,
            'problems' => $strategy->problems,
        ];
    }
}
