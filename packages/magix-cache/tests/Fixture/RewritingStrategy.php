<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Async\Promise;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Override;

/**
 * Makes read and write request transformations observable through nesting.
 */
final readonly class RewritingStrategy implements CacheStrategy
{
    /**
     * Supplies the prefix used by this middleware.
     */
    public function __construct(private string $prefix)
    {
    }

    /**
     * @param Closure(string): (CacheRead<mixed>|null) $next
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        $read = $next($this->prefix.$key);

        return $read === null ? null : new CacheRead($read->cached->map(fn (mixed $value): array => [$this->prefix, $value]), $read->retainedUntil);
    }

    /**
     * @param Closure(): Promise<Cached<mixed>> $next
     * @return Promise<Cached<mixed>>
     */
    #[Override]
    public function fetch(string $key, Closure $next): Promise
    {
        return $next();
    }

    /**
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $request, Closure $next): void
    {
        $next($this->prefix.$key, new CacheWrite(
            $request->cached->map(fn (mixed $value): array => [$this->prefix, $value]),
            $request->retainedUntil,
        ));
    }
}
