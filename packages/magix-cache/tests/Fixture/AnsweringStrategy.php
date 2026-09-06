<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Override;

/**
 * Answers every operation itself without delegating, like a terminal.
 */
final class AnsweringStrategy implements CacheStrategy
{
    /**
     * @var Cached<mixed>|null
     */
    public ?Cached $stored = null;

    /**
     * @param Cached<mixed>|null $hit
     * @param Cached<mixed> $fetched
     * @param bool $succeeds False mimics a served stale candidate: no base time, store suppressed.
     */
    public function __construct(
        private readonly ?Cached $hit,
        private readonly Cached $fetched,
        private readonly bool $succeeds = true,
    ) {
    }

    /**
     * @return Cached<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        return $this->hit;
    }

    /**
     * @return Cached<mixed>
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        if ($this->succeeds) {
            $operation->stampBaseTime($operation->now());
        } else {
            $operation->suppressStore();
        }

        return $this->fetched;
    }

    /**
     * @param Cached<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
        $this->stored = $result;
    }
}
