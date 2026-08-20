<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Override;

/**
 * Implements the feed with a ten second cache.
 */
final class RecentFeedQuery implements FeedQuery
{
    use Cacheable;

    /**
     * Returns the most recent feed entries.
     *
     * @return Cached<string>
     */
    #[Cache(ttl: 10, tags: ['feed'])]
    #[Override]
    public function execute(string $channel): Cached
    {
        return $this->cached(static fn (): Cached => Cached::of($channel));
    }
}
