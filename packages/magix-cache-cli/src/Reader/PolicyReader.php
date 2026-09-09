<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Reader;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;
use function is_string;

use Magix\Cache\Cli\Declaration\PolicyDeclaration;
use Magix\Cache\Cli\Declaration\PolicySource;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Magix\Cache\Runtime\Policy\Ttl;
use PhpParser\Node\Arg;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Reads a cache policy from the arguments written in the source code.
 */
final readonly class PolicyReader
{
    /**
     * Parameter order of the #[Cache] attribute.
     */
    private const array OPTIONS = ['ttl', 'maxTtl', 'tags', 'visibility', 'version', 'runtime'];

    /**
     * Creates a policy reader.
     */
    public function __construct(private ArgumentReader $arguments = new ArgumentReader())
    {
    }

    /**
     * Returns the policy declared by the given attribute arguments.
     *
     * @param array<Arg|VariadicPlaceholder> $arguments
     */
    public function read(array $arguments, PolicySource $source): PolicyDeclaration
    {
        $values = $this->arguments->values($arguments, self::OPTIONS);
        $ttl = $values['ttl'] ?? Ttl::Auto;
        $maxTtl = $values['maxTtl'] ?? null;
        $tags = $values['tags'] ?? null;
        $visibility = $values['visibility'] ?? null;
        $version = $values['version'] ?? '1';
        $runtime = $values['runtime'] ?? CacheRuntimeRegistry::DEFAULT_NAME;

        return new PolicyDeclaration(
            source: $source,
            ttl: is_int($ttl) || $ttl instanceof Ttl ? $ttl : null,
            maxTtl: is_int($maxTtl) ? $maxTtl : null,
            tags: is_array($tags) ? array_values(array_filter($tags, is_string(...))) : null,
            visibility: $visibility instanceof Visibility ? $visibility : null,
            version: is_string($version) && $version !== LiteralReader::UNRESOLVED ? $version : '1',
            runtime: is_string($runtime) && $runtime !== LiteralReader::UNRESOLVED ? $runtime : CacheRuntimeRegistry::DEFAULT_NAME,
            tagsUnknown: $tags !== null && !is_array($tags),
            visibilityUnknown: $visibility !== null && !$visibility instanceof Visibility,
        );
    }
}
