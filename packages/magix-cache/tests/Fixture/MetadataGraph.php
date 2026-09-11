<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\CacheVisibility;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Seven boundaries with depth, fan-out, and a leaf shared by both branches.
 */
final class MetadataGraph
{
    use Cacheable;

    /**
     * Origin calls per boundary.
     *
     * @var array<string, int>
     */
    public array $calls = [];

    /**
     * Whether leaf origins report a declared outage.
     */
    public bool $unavailable = false;

    /**
     * Configures the first source; it stays unchanged throughout each scenario.
     */
    public function __construct(
        private readonly CacheMetadata $source,
        private readonly int $sourceTtl = 20,
        private readonly Visibility $sourceVisibility = Visibility::Shared,
    ) {
    }

    /**
     * Combines both branches under a fixed policy and a parameter TTL.
     *
     * @return Cached<string>
     */
    #[Cache(ttl: 120, tags: ['root', 'common'])]
    public function root(#[CacheTtl] int $ttl = 90): Cached
    {
        return $this->cached(function (): Cached {
            $this->calls['root'] = ($this->calls['root'] ?? 0) + 1;

            return $this->left()->combine2($this->right())->map(
                static fn (string $left, string $right): string => $left.'|'.$right,
            );
        });
    }

    /**
     * Derives its lifetime from two sequential dependencies.
     *
     * @return Cached<string>
     */
    #[Cache(ttl: Ttl::Auto, tags: ['left', 'common'])]
    public function left(): Cached
    {
        return $this->cached(function (): Cached {
            $this->calls['left'] = ($this->calls['left'] ?? 0) + 1;

            return $this->leaf(0, $this->sourceTtl, $this->sourceVisibility, ['parameter', 'common'])
                ->flatMap(fn (string $first): Cached => $this->leaf(1, 60)->map(
                    static fn (string $second): string => $first.','.$second,
                ));
        });
    }

    /**
     * Inherits from a collection that also contains the left branch's second leaf.
     *
     * @return Cached<string>
     */
    #[Cache(tags: ['right', 'common'])]
    public function right(): Cached
    {
        return $this->cached(function (): Cached {
            $this->calls['right'] = ($this->calls['right'] ?? 0) + 1;

            return Cached::traverse([1, 2, 3], fn (int $id): Cached => $this->leaf($id, 60))
                ->map(static fn (array $values): string => implode(',', $values));
        });
    }

    /**
     * @param list<string> $tags
     * @return Cached<string>
     * @throws UpstreamUnavailable when the source is unavailable
     */
    #[Cache(ttl: 90, tags: ['leaf', 'common'])]
    #[DynamicTtl(resolver: FixedTtlResolver::class)]
    #[UseStrategy(ProductCacheStrategy::class, min: 45)]
    public function leaf(
        int $id,
        #[CacheTtl] int $ttl,
        #[CacheVisibility] Visibility $visibility = Visibility::Shared,
        #[CacheTags] array $tags = [],
    ): Cached {
        return $this->cached(function () use ($id): Cached {
            $this->calls['leaf:'.$id] = ($this->calls['leaf:'.$id] ?? 0) + 1;

            if ($this->unavailable) {
                throw new UpstreamUnavailable('source unavailable');
            }

            $metadata = new CacheMetadata(tags: ['source:'.$id, 'common'], reasons: ['source:'.$id]);

            return Cached::of('leaf:'.$id, $id === 0 ? $metadata->meet($this->source) : $metadata);
        });
    }

}
