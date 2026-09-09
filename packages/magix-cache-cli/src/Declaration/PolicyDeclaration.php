<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function implode;
use function is_int;

use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Holds one cache policy exactly as it is written in the source code.
 */
final readonly class PolicyDeclaration
{
    /**
     * Creates a statically read cache policy.
     *
     * @param int|Ttl|null $ttl Null when the declared expression cannot be read statically.
     * @param list<string>|null $tags Null inherits; an empty list clears.
     * @param string $runtime Name of the runtime the declaration references.
     */
    public function __construct(
        public PolicySource $source,
        public int|Ttl|null $ttl = Ttl::Auto,
        public ?int $maxTtl = null,
        public ?array $tags = null,
        public ?Visibility $visibility = null,
        public string $version = '1',
        public string $runtime = CacheRuntimeRegistry::DEFAULT_NAME,
        public bool $tagsUnknown = false,
        public bool $visibilityUnknown = false,
    ) {
    }

    /**
     * Returns the policy rendered as a compact source-like summary.
     */
    public function label(): string
    {
        $options = $this->ttl === Ttl::Auto ? [] : ['ttl: '.$this->ttlLabel()];

        if ($this->maxTtl !== null) {
            $options[] = 'maxTtl: '.$this->maxTtl;
        }

        if ($this->tags !== null) {
            $options[] = 'tags: ['.implode(', ', $this->tags).']';
        }

        if ($this->visibility !== null) {
            $options[] = 'visibility: '.$this->visibility->name;
        }

        if ($this->version !== '1') {
            $options[] = 'version: '.$this->version;
        }

        if ($this->runtime !== CacheRuntimeRegistry::DEFAULT_NAME) {
            $options[] = 'runtime: '.$this->runtime;
        }

        return $options === [] ? '#[Cache]' : '#[Cache('.implode(', ', $options).')]';
    }

    /**
     * Returns the declared TTL rendered for human readable output.
     */
    public function ttlLabel(): string
    {
        if (is_int($this->ttl)) {
            return $this->ttl.'s';
        }

        if ($this->ttl === null) {
            return 'unresolved';
        }

        return 'Ttl::'.$this->ttl->name;
    }
}
