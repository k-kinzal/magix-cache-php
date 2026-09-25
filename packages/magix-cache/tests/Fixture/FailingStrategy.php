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
use RuntimeException;

/**
 * A delegate whose successful-result processing fails with declared behavior.
 */
final readonly class FailingStrategy implements CacheStrategy
{
    /**
     * Creates a failing delegate.
     */
    public function __construct(private string $message)
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
     * @return Promise<Cached<mixed>>
     * @throws RuntimeException when the strategy is executed
     * @param Closure(): Promise<Cached<mixed>> $next
     */
    #[Override]
    public function fetch(string $key, Closure $next): Promise
    {
        throw new RuntimeException($this->message);
    }

    /**
     * @param CacheWrite<mixed> $result
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $result, Closure $next): void
    {
    }
}
