<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Metadata\Visibility;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Declares boundaries that cannot behave the way they are written.
 */
final class BrokenQuery
{
    use Cacheable;

    /**
     * Returns a value without declaring any policy.
     *
     * @return Cached<int>
     */
    public function undeclared(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($id));
    }

    /**
     * Returns a value that inherits an expiration that never exists.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: Ttl::Auto)]
    public function inherited(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($id));
    }

    /**
     * Returns a value keyed on a parameter that is ignored and scoped.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 10)]
    public function conflicted(
        #[CacheIgnore]
        #[CacheScope(Visibility::Private)]
        int $viewerId,
    ): Cached {
        return $this->cached(static fn (): Cached => Cached::of($viewerId));
    }

    /**
     * Returns a value keyed on a value that cannot be hashed reliably.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 10)]
    public function opaque(object $filter): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of(1));
    }
}
