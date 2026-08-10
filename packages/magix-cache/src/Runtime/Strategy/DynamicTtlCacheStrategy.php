<?php

declare(strict_types=1);

namespace Magix\Cache\Runtime\Strategy;

use Closure;
use InvalidArgumentException;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Operation\OriginFetch;
use Magix\Cache\Runtime\Operation\OriginFetchOutcome;
use Magix\Cache\Runtime\Operation\OriginFetchResult;

use function min;

use Override;

/**
 * Derives a monotone TTL constraint from each successful origin result.
 */
final readonly class DynamicTtlCacheStrategy extends CacheStrategyMiddleware
{
    /** @var Closure(DynamicTtlContext): int */
    private Closure $resolve;

    /**
     * @param Closure(DynamicTtlContext): int $resolve
     */
    public function __construct(Closure $resolve)
    {
        $this->resolve = $resolve;
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
        $fetched = $next($operation);

        if ($fetched->outcome !== OriginFetchOutcome::Origin) {
            return $fetched;
        }

        $result = $fetched->originValue();
        $now = $operation->now();
        $ttl = ($this->resolve)(new DynamicTtlContext($operation->key, $result, $now));

        if ($ttl < 0) {
            throw new InvalidArgumentException('A dynamically resolved TTL must be zero or greater.');
        }

        $dynamicExpiration = $now + $ttl;
        $currentExpiration = $result->metadata->expiresAt;
        $expiration = $currentExpiration === null
            ? $dynamicExpiration
            : min($currentExpiration, $dynamicExpiration);

        return new OriginFetchResult(Cached::of(
            $result->value(),
            $result->metadata->withExpiresAt($expiration),
        ));
    }
}
