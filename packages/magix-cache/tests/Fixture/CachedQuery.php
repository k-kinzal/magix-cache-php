<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;
use Magix\Cache\Runtime\Strategy\DynamicTtlCacheStrategy;

/**
 * Exercises Cacheable through a representative query method.
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
    #[Cache(ttl: Ttl::Auto)]
    public function auto(Cached $dependency): Cached
    {
        return $this->cached(fn (): Cached => Cached::of('auto', $dependency->metadata));
    }

    /**
     * Returns a value with upstream cache metadata.
     *
     * @return Cached<string>
     */
    #[Cache(ttl: Ttl::FromUpstream, maxTtl: 10)]
    public function upstream(float $expiresAt): Cached
    {
        return $this->cached(
            fn (): Cached => Cached::of('upstream', new CacheMetadata(expiresAt: $expiresAt)),
        );
    }

    /**
     * Returns a value configured without a cache attribute.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    public function explicit(int $id): Cached
    {
        return $this->cached(
            function () use ($id): Cached {
                ++$this->calls;

                return Cached::of('explicit:'.$id);
            },
            new CachePolicy(ttl: 15, tags: ['explicit']),
        );
    }

    /**
     * Returns a value with a strategy configured for this cache boundary.
     *
     * @return Cached<lowercase-string&non-falsy-string>
     */
    public function dynamic(int $id): Cached
    {
        return $this->cached(
            function () use ($id): Cached {
                ++$this->calls;

                return Cached::of('dynamic:'.$id);
            },
            new CachePolicy(ttl: Ttl::Auto),
            new DynamicTtlCacheStrategy(static fn (): int => 7),
        );
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
}
