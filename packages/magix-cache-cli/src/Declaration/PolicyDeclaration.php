<?php

declare(strict_types=1);

namespace Magix\Cache\Cli\Declaration;

use function implode;
use function is_int;

use Magix\Cache\Runtime\Metadata\Visibility;
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
     * @param list<string> $tags
     */
    public function __construct(
        public PolicySource $source,
        public int|Ttl|null $ttl = Ttl::Auto,
        public ?int $maxTtl = null,
        public array $tags = [],
        public Visibility $visibility = Visibility::Shared,
        public bool $clamp = true,
        public string $version = '1',
    ) {
    }

    /**
     * Returns the policy rendered as a compact source-like summary.
     */
    public function label(): string
    {
        $options = ['ttl: '.$this->ttlLabel()];

        if ($this->maxTtl !== null) {
            $options[] = 'maxTtl: '.$this->maxTtl;
        }

        if ($this->tags !== []) {
            $options[] = 'tags: ['.implode(', ', $this->tags).']';
        }

        if ($this->visibility !== Visibility::Shared) {
            $options[] = 'visibility: '.$this->visibility->name;
        }

        if (!$this->clamp) {
            $options[] = 'clamp: false';
        }

        if ($this->version !== '1') {
            $options[] = 'version: '.$this->version;
        }

        return '#[Cache('.implode(', ', $options).')]';
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
