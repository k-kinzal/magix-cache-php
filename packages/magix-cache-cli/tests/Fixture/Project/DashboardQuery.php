<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

/**
 * Reads the viewer from the session instead of from its own arguments.
 */
final class DashboardQuery
{
    use Cacheable;

    /**
     * Creates the dashboard query.
     */
    public function __construct(
        private readonly ViewerQuery $viewer,
        private readonly ViewerSession $session,
    ) {
    }

    /**
     * Returns the dashboard payload.
     *
     * @return Cached<int>
     */
    #[Cache(ttl: 45, tags: ['dashboard'])]
    public function execute(string $section): Cached
    {
        return $this->cached(function () use ($section): Cached {
            $viewer = $this->viewer->execute($this->session->viewerId());

            return Cached::of($viewer->value() + strlen($section), $viewer->metadata);
        });
    }
}
