<?php

declare(strict_types=1);

namespace Tests\Fixture;

use ArrayObject;
use Closure;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
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
     * @return CacheRead<mixed>|null
     * @param Closure(string): (CacheRead<mixed>|null) $next
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        $this->record($this->name.'.get.before');
        $result = $next($key);
        $this->record($this->name.'.get.after');

        return $result;
    }

    /**
     * @return Cached<mixed>
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    public function fetch(string $key, Closure $next): Cached
    {
        $this->record($this->name.'.fetch.before');
        $result = $next();
        $this->record($this->name.'.fetch.after');

        return $result;
    }

    /**
     * @param CacheWrite<mixed> $result
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $result, Closure $next): void
    {
        $this->record($this->name.'.set.before');
        $next($key, $result);
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
