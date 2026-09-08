<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Attribute\DynamicTtl;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Exercises Cacheable through representative attribute-declared boundaries.
 */
final class CachedQuery
{
    use Cacheable;

    /**
     * Number of computation executions.
     */
    public int $calls = 0;

    /**
     * Returns a cached value for an identifier.
     *
     * @return Cached<non-falsy-string>
     */
    #[Cache(ttl: 20, tags: ['query'])]
    public function execute(int $id, #[CacheIgnore] string $trace = ''): Cached
    {
        return $this->cached(function () use ($id, $trace): Cached {
            ++$this->calls;

            return Cached::of($id.':'.$trace);
        });
    }

    /**
     * Returns a value whose expiration is inherited from a dependency.
     *
     * @param Cached<string> $dependency
     * @return Cached<string>
     */
    #[Cache]
    public function auto(Cached $dependency): Cached
    {
        return $this->cached(fn (): Cached => Cached::of('auto', $dependency->metadata));
    }

    /**
     * Returns a value constrained by a declared dynamic-TTL resolver.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    #[Cache(ttl: Ttl::Auto)]
    #[DynamicTtl(resolver: FixedTtlResolver::class)]
    public function dynamic(int $id): Cached
    {
        return $this->cached(function () use ($id): Cached {
            ++$this->calls;

            return Cached::of('dynamic:'.$id);
        });
    }

    /**
     * Executes without deriving a key or storing its result.
     *
     * @param Closure(): string $value
     * @return Cached<string>
     */
    #[Cache(ttl: 10)]
    public function noStore(
        #[CacheIgnore]
        #[CacheScope(Visibility::NoStore)]
        Closure $value,
    ): Cached {
        return $this->cached(function () use ($value): Cached {
            ++$this->calls;

            return Cached::of($value());
        });
    }

    /**
     * Returns a personalized value separated by its scoped parameter.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    #[Cache(ttl: 10)]
    public function personal(#[CacheScope] int $userId): Cached
    {
        return $this->cached(function () use ($userId): Cached {
            ++$this->calls;

            return Cached::of('personal:'.$userId);
        });
    }

}
