<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheWrite;
use RuntimeException;

/**
 * Supplies independent read, origin and write closures for middleware tests.
 */
final class CacheHandlers
{
    /**
     * @var CacheWrite<mixed>|null
     */
    public ?CacheWrite $stored = null;

    /**
     * @param Cached<mixed>|null $hit
     * @param Cached<mixed> $fetched
     */
    public function __construct(
        private readonly ?Cached $hit,
        private readonly Cached $fetched,
        private readonly ?float $retainedUntil = null,
        private readonly ?RuntimeException $error = null,
    ) {
    }

    /**
     * @return CacheRead<mixed>|null
     */
    public function get(string $key): ?CacheRead
    {
        return $this->hit === null ? null : new CacheRead($this->hit, $this->retainedUntil ?? $this->hit->metadata->expiresAt ?? 150.0);
    }

    /**
     * @return Promise<Cached<mixed>>
     * A configured inquiry failure rejects the returned promise.
     */
    public function fetch(): Promise
    {
        if ($this->error !== null) {
            return Promise::rejected($this->error);
        }

        return Promise::resolved($this->fetched);
    }

    /**
     * @param CacheWrite<mixed> $result
     */
    public function set(string $key, CacheWrite $result): void
    {
        $this->stored = $result;
    }

}
