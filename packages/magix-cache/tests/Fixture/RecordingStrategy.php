<?php

declare(strict_types=1);

namespace Tests\Fixture;

use ArrayObject;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\NextCacheStrategy;
use Override;

/**
 * Records the order in which its operations run, then delegates.
 */
final class RecordingStrategy implements CacheStrategy
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param ArrayObject<int, string>|null $log Shared log for cross-strategy ordering.
     */
    public function __construct(
        private readonly string $name,
        private readonly ?ArrayObject $log = null,
    ) {
    }

    /**
     * @return Cached<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        $this->record($this->name.'.get.before');
        $result = $next->get($operation);
        $this->record($this->name.'.get.after');

        return $result;
    }

    /**
     * @return Cached<mixed>
     */
    #[Override]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        $this->record($this->name.'.fetch.before');
        $result = $next->fetch($operation);
        $this->record($this->name.'.fetch.after');

        return $result;
    }

    /**
     * @param Cached<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
        $this->record($this->name.'.set.before');
        $next->set($operation, $result);
        $this->record($this->name.'.set.after');
    }

    /**
     * Appends one call to the local and the shared log.
     */
    public function record(string $call): void
    {
        $this->calls[] = $call;
        $this->log?->append($call);
    }
}
