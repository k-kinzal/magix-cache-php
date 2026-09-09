<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

use Magix\Cache\Strategy\CacheAnswer;
use Magix\Cache\Strategy\CacheOperation;
use Magix\Cache\Strategy\CacheRead;
use Magix\Cache\Strategy\CacheStrategy;
use Magix\Cache\Strategy\CacheWrite;
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\NextCacheStrategy;
use Magix\Cache\Strategy\OriginFailure;
use Magix\Cache\Strategy\OriginResult;

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
     * @return CacheRead<mixed>|null
     */
    #[Override]
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead
    {
        return $next->get($operation);
    }

    /**
     * @return OriginResult<mixed>|OriginFailure|CacheAnswer<mixed>
     */
    #[Override]
    #[Ttl(min: new ConstructorArg('minimum'))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        $result = $next->fetch($operation);

        if (!$result instanceof OriginResult) {
            return $result;
        }

        $volatility = max($this->minimum, $this->lifetime($result->cached->value()));

        return $result->withTtl($volatility);
    }

    /**
     * @param CacheWrite<mixed> $result
     */
    #[Override]
    public function set(CacheOperation $operation, CacheWrite $result, NextCacheStrategy $next): void
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
