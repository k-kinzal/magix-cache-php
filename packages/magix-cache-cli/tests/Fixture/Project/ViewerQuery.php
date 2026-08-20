<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Metadata\Visibility;

/**
 * Loads the personalized part of a page.
 */
final class ViewerQuery
{
    use Cacheable;

    /**
     * Returns the viewer profile.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 30, tags: ['viewer'])]
    public function execute(
        #[CacheScope(Visibility::Private)]
        int $viewerId,
    ): Cached {
        return $this->cached(static fn (): Cached => Cached::of($viewerId));
    }
}
