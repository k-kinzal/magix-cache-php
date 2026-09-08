<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Render;

use function array_map;
use function json_encode;

use JsonException;
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
            count($trees) === 1 ? $trees[0] : $trees,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Returns one cache tree as plain data.
     *
     * @return array<string, mixed>
     */
    public function tree(CacheNode $node): array
    {
        $boundary = $node->boundary;
        $effect = $node->effect;
        $policy = $boundary->policy;

        return [
            'boundary' => $boundary->id(),
            'kind' => $boundary->isCacheBoundary ? 'boundary' : 'entry-point',
            'file' => $boundary->file,
            'line' => $boundary->line,
            'comment' => $boundary->comment,
            'policy' => $policy === null ? null : [
                'source' => $policy->source->name,
                'ttl' => $policy->ttlLabel(),
                'maxTtl' => $policy->maxTtl,
                'tags' => $policy->tags,
                'visibility' => strtolower($policy->visibility->name),
                'version' => $policy->version,
                'runtime' => $policy->runtime,
            ],
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
            'effective' => [
                'ttl' => $effect->ttl->jsonSerialize(),
                'visibility' => strtolower($effect->visibility->name),
                'visibilityReason' => $effect->visibilityReason,
                'visibilityUnknown' => $effect->visibilityUnknown,
                'tagsUnknown' => $effect->tagsUnknown,
                'storable' => $effect->storable,
                'tags' => $effect->tags,
                'problems' => $effect->problems,
            ],
            'notes' => $node->notes,
            'dependencies' => array_map($this->tree(...), $node->children),
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
            'addsConstraint' => $strategy->addsConstraint,
            'steps' => array_map(
                static fn (StrategyStep $step): array => [
                    'strategy' => $step->strategy,
                    'ttl' => $step->ttl->jsonSerialize(),
                    'assumed' => $step->assumed,
                ],
                $strategy->steps,
            ),
            'problems' => $strategy->problems,
        ];
    }
}
