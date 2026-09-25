<?php

declare(strict_types=1);

namespace Tests\Fixture;

use Closure;
use Magix\Cache\Cached;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Supplies its own origin answer without delegating the inquiry.
 */
final readonly class ImmediateStrategy implements CacheStrategy
{
    /**
     * Supplies the clock used for relative expiration.
     */
    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    /**
     * @return CacheRead<mixed>|null
     * @param Closure(string): (CacheRead<mixed>|null) $next
     */
    #[Override]
    public function get(string $key, Closure $next): ?CacheRead
    {
        return $next($key);
    }

    /**
     * @return Cached<string>
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    public function fetch(string $key, Closure $next): Cached
    {
        return Cached::of('immediate', new CacheMetadata(expiresAt: (float) $this->clock->now()->format('U.u') + 30.0));
    }

    /**
     * @param CacheWrite<mixed> $request
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $request, Closure $next): void
    {
        $next($key, $request);
    }
}
