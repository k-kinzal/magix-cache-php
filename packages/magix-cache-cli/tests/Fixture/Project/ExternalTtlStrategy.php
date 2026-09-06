<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Override;

/**
 * A strategy that publishes no lifetime contract at all.
 */
final readonly class ExternalTtlStrategy implements CacheStrategy
{
    /**
     * @return Cached<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        return $next->get($operation);
    }

    /**
     * @return Cached<mixed>
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        return $next->fetch($operation);
    }

    /**
     * @param Cached<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
        $next->set($operation, $result);
    }
}
