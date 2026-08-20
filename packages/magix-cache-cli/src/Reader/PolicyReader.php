<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_filter;
use function array_values;

use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Arg;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Reads a cache policy from the arguments written in the source code.
 */
final readonly class PolicyReader
{
    /**
     * Parameter order shared by #[Cache] and CachePolicy.
     */
    private const array OPTIONS = ['ttl', 'maxTtl', 'tags', 'visibility', 'clamp', 'version'];

    /**
     * Creates a policy reader.
     */
    public function __construct(private ArgumentReader $arguments = new ArgumentReader())
    {
    }

    /**
     * Returns the policy declared by the given attribute or constructor call.
     *
     * @param array<Arg|VariadicPlaceholder> $arguments
     */
    public function read(array $arguments, PolicySource $source): PolicyDeclaration
    {
        $values = $this->arguments->values($arguments, self::OPTIONS);
        $ttl = $values['ttl'] ?? Ttl::Auto;
        $maxTtl = $values['maxTtl'] ?? null;
        $tags = $values['tags'] ?? [];
        $visibility = $values['visibility'] ?? Visibility::Shared;
        $clamp = $values['clamp'] ?? true;
        $version = $values['version'] ?? '1';

        return new PolicyDeclaration(
            source: $source,
            ttl: is_int($ttl) || $ttl instanceof Ttl ? $ttl : null,
            maxTtl: is_int($maxTtl) ? $maxTtl : null,
            tags: is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [],
            visibility: $visibility instanceof Visibility ? $visibility : Visibility::Shared,
            clamp: is_bool($clamp) ? $clamp : true,
            version: is_string($version) ? $version : '1',
        );
    }
}
