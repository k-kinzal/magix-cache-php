<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Override;

/**
 * A strategy that publishes no lifetime contract at all.
 */
final readonly class ExternalTtlStrategy implements CacheStrategy
{
    /**
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $next->get($operation);
    }

    /**
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        return $next->fetch($operation);
    }

    /**
     * @param CacheWrite<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
        $next->set($operation, $result);
    }
}
