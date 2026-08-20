<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Cached;

/**
 * Declares the feed a home page depends on.
 */
interface FeedQuery
{
    /**
     * Returns the feed entries.
     *
     * @return Cached<string>
     */
    public function execute(string $channel): Cached;
}
