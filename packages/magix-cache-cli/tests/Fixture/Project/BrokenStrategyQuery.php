<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Declares strategy usages that cannot work as written.
 */
final class BrokenStrategyQuery
{
    use Cacheable;

    /**
     * References a strategy class without a static create().
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 30)]
    #[UseStrategy(strategy: ExternalTtlStrategy::class)]
    public function withoutCreate(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($id));
    }

    /**
     * Passes an argument that create() does not declare.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 30)]
    #[UseStrategy(strategy: ProductCacheStrategy::class, minimum: 60)]
    public function withUnknownArgument(int $id): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($id));
    }
}
