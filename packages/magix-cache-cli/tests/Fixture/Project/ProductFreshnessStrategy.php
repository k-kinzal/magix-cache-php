<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Closure;
use Magix\Cache\Cached;
use Magix\Cache\Clock\SystemClock;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;

use function max;

use Override;
use Psr\Clock\ClockInterface;

/**
 * Derives a freshness lifetime from the fetched data with a floor.
 */
final readonly class ProductFreshnessStrategy implements CacheStrategy
{
    /**
     * Creates a freshness strategy with a floor.
     */
    public function __construct(
        private int $minimum,
        private ClockInterface $clock = new SystemClock(),
    ) {
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
     * @return Cached<mixed>
     * @param Closure(): Cached<mixed> $next
     */
    #[Override]
    #[Ttl(min: new ConstructorArg('minimum'))]
    public function fetch(string $key, Closure $next): Cached
    {
        $result = $next();

        $volatility = max($this->minimum, $this->lifetime($result->value()));

        return Cached::of($result->value(), $result->metadata->withExpiration((float) $this->clock->now()->format('U.u') + ($volatility)));
    }

    /**
     * @param CacheWrite<mixed> $result
     * @param Closure(string, CacheWrite<mixed>): void $next
     */
    #[Override]
    public function set(string $key, CacheWrite $result, Closure $next): void
    {
        $next($key, $result);
    }

    /**
     * Returns a data-derived lifetime that only the runtime can decide.
     */
    public function lifetime(mixed $value): int
    {
        return $value === null ? $this->minimum : $this->minimum * 2;
    }

}
