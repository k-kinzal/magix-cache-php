<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Operation\CacheSet;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchResult;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use Override;

/**
 * Records completion order for every strategy phase.
 */
final readonly class RecordingCacheStrategy extends CacheStrategyMiddleware
{
    /** @var Closure(string): void */
    private Closure $record;

    /**
     * @param Closure(string): void $record
     */
    public function __construct(
        private string $label,
        Closure $record,
    ) {
        $this->record = $record;
    }

    /**
     * @template T
     * @param Closure(CacheGet): (CacheEntry<T>|null) $next
     * @return CacheEntry<T>|null
     */
    #[Override]
    public function get(CacheGet $operation, Closure $next): ?CacheEntry
    {
        $result = $next($operation);
        ($this->record)($this->label);

        return $result;
    }

    /**
     * @template T
     * @param OriginFetch<T> $operation
     * @param Closure(OriginFetch<T>): OriginFetchResult<T> $next
     * @return OriginFetchResult<T>
     */
    #[Override]
    public function fetch(OriginFetch $operation, Closure $next): OriginFetchResult
    {
        $result = $next($operation);
        ($this->record)($this->label);

        return $result;
    }

    /**
     * @template T
     * @param CacheSet<T> $operation
     * @param Closure(CacheSet<T>): void $next
     */
    #[Override]
    public function set(CacheSet $operation, Closure $next): void
    {
        $next($operation);
        ($this->record)($this->label);
    }
}
