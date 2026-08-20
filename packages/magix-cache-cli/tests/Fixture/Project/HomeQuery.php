<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Policy\Ttl;

/**
 * Inherits its expiration from the feed it renders.
 */
final class HomeQuery
{
    use Cacheable;

    /**
     * Creates the home query.
     */
    public function __construct(private readonly FeedQuery $feed)
    {
    }

    /**
     * Returns the home page payload.
     *
     * @return Cached<string>
     */
    #[Cache(ttl: Ttl::Auto, tags: ['home'])]
    public function execute(string $channel): Cached
    {
        return $this->cached(function () use ($channel): Cached {
            $feed = $this->feed->execute($channel);

            return Cached::of($feed->value(), $feed->metadata);
        });
    }
}
