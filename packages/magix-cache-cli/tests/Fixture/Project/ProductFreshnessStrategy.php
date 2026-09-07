<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Cached;
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\NextCacheStrategy;

use function max;

use Override;

/**
 * Derives a freshness lifetime from the fetched data with a floor.
 */
final readonly class ProductFreshnessStrategy implements CacheStrategy
{
    /**
     * Creates a freshness strategy with a floor.
     */
    public function __construct(private int $minimum)
    {
    }

    /**
     * @return Cached<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached
    {
        return $next->get($operation);
    }

    /**
     * @return Cached<mixed>
     */
    #[Override]
    #[Ttl(min: new ConstructorArg('minimum'))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        $result = $next->fetch($operation);

        if (!$operation->originSucceeded()) {
            return $result;
        }

        $volatility = max($this->minimum, $this->lifetime($result->value()));

        return Cached::of($result->value(), $result->metadata->meet(CacheMetadata::forTtl($volatility, $operation->baseTime())));
    }

    /**
     * @param Cached<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void
    {
        $next->set($operation, $result);
    }

    /**
     * Returns a data-derived lifetime that only the runtime can decide.
     */
    public function lifetime(mixed $value): int
    {
        return $value === null ? $this->minimum : $this->minimum * 2;
    }
}
