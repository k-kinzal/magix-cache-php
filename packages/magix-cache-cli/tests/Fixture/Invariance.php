<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture;

use function base64_encode;

use Magix\Cache\Cli\Declaration\Catalog;
use Magix\Cache\Cli\Graph\CacheNode;
use Magix\Cache\Cli\Graph\CacheTree;
use Magix\Cache\Cli\Source\SourceParser;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Analyzes inline sources so meaning-preserving rewrites can be compared.
 *
 * A rewrite that does not change what the code computes must not change what
 * the analyzer reports. Comparing two summaries states that property directly,
 * without pinning values that may legitimately evolve.
 */
final readonly class Invariance
{
    /**
     * Returns the analyzed root of one inline source.
     *
     * @throws AssertionFailedError when the reference names nothing in the source
     */
    public static function analyze(string $source, string $reference): CacheNode
    {
        $parser = new SourceParser();
        $uri = 'data:text/plain;base64,'.base64_encode($source);
        $catalog = new Catalog($parser->parse($uri, 'invariance.php', $parser->constants($uri)));
        $matches = $catalog->search($reference);

        if ($matches === []) {
            throw new AssertionFailedError($reference.' was not found in the analyzed source.');
        }

        return (new CacheTree($catalog))->build($matches[0]);
    }

    /**
     * Returns the reported metadata, excluding provenance.
     *
     * A rewrite may legitimately attribute an inherited value to a different
     * node, so the explanation is not part of the invariant; the value is.
     *
     * @return array<string, mixed>
     */
    public static function summary(CacheNode $node): array
    {
        $effect = $node->effect;

        return [
            'ttl' => $effect->ttl->label(),
            'state' => $effect->ttl->state->value,
            'visibility' => $effect->visibilityLabel(),
            'tags' => $effect->tagsLabel(),
            'storable' => $effect->storable,
            'problems' => $effect->problems,
            'warnings' => $node->effect->analysis->causes(),
            'key' => self::key($node),
        ];
    }

    /**
     * Returns the analyzed summary of one inline source in one step.
     *
     * @return array<string, mixed>
     * @throws AssertionFailedError when the reference names nothing in the source
     */
    public static function summarize(string $source, string $reference): array
    {
        return self::summary(self::analyze($source, $reference));
    }

    /**
     * Returns the key-forming declaration, which a rewrite must also preserve.
     */
    public static function key(CacheNode $node): string
    {
        $parameters = [];

        foreach ($node->boundary->parameters as $parameter) {
            $parameters[] = ($parameter->ignored ? '-' : '').$parameter->name;
        }

        return implode(',', $parameters).'@'.($node->boundary->policy->version ?? '-');
    }

    /**
     * Wraps class bodies in the imports every inline fixture needs.
     */
    public static function source(string $classes): string
    {
        return <<<PHP
            <?php
            use Magix\\Cache\\Attribute\\Cache;
            use Magix\\Cache\\Attribute\\UseStrategy;
            use Magix\\Cache\\Cacheable;
            use Magix\\Cache\\Cached;
            use Magix\\Cache\\Metadata\\Visibility;
            use Magix\\Cache\\Runtime\\Policy\\Ttl;
            use Magix\\Cache\\Strategy\\CacheStrategy;
            use Magix\\Cache\\Strategy\\StrategyDefinition;
            use Magix\\Cache\\Strategy\\CompositeCacheStrategy;
            {$classes}
            PHP;
    }
}
