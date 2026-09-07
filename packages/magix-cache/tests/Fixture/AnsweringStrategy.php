<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;
use Override;
use RuntimeException;

/**
 * Answers every operation itself without delegating, like a terminal.
 */
final class AnsweringStrategy implements CacheStrategy
{
    /**
     * @var CacheWrite<mixed>|null
     */
    public ?CacheWrite $stored = null;

    /**
     * @param Cached<mixed>|null $hit
     * @param Cached<mixed> $fetched
     * @param bool $succeeds False mimics a served stale candidate: no base time, store suppressed.
     */
    public function __construct(
        private readonly ?Cached $hit,
        private readonly Cached $fetched,
        private readonly bool $succeeds = true,
        private readonly ?RuntimeException $failure = null,
        private readonly ?float $retainedUntil = null,
    ) {
    }

    /**
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $this->hit === null ? null : new CacheRead($this->hit, $this->retainedUntil ?? $this->hit->metadata->expiresAt ?? 150.0);
    }

    /**
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        if ($this->failure !== null) {
            return new OriginFailure($this->failure);
        }

        return $this->succeeds
            ? new OriginResult($this->fetched, $operation->now())
            : new CacheAnswer($this->fetched);
    }

    /**
     * @param CacheWrite<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
    {
        $this->stored = $result;
    }
}
