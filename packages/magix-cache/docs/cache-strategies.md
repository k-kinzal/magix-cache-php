# Cache Strategies

This guide explains how to customize cache reads, origin fetches, and cache writes for one cache boundary.

## Policy and Strategy Responsibilities

A `CachePolicy` declares constraints such as TTL, visibility, tags, and version. A `CacheStrategy` controls operations:

```text
cache get -> origin fetch -> cache set
```

Strategies are passed as the third argument to `Cacheable::cached()` and apply only to that boundary:

```php
return $this->cached(
    compute: fn (): Cached => Cached::of($this->origin->fetch()),
    policy: new CachePolicy(ttl: 30),
    strategy: $strategy,
);
```

When a method uses `#[Cache]`, pass `null` as the policy to reach the strategy argument:

```php
return $this->cached(
    fn (): Cached => Cached::of($this->origin->fetch()),
    null,
    $strategy,
);
```

`PassThroughCacheStrategy` is the default and delegates every operation unchanged.

## Dynamic TTL

`DynamicTtlCacheStrategy` derives a TTL from each successful origin result:

```php
<?php

use Magix\Cache\CachePolicy;
use Magix\Cache\Runtime\Policy\Ttl;
use Magix\Cache\Runtime\Strategy\DynamicTtlCacheStrategy;
use Magix\Cache\Runtime\Strategy\DynamicTtlContext;

$strategy = new DynamicTtlCacheStrategy(
    static fn (DynamicTtlContext $context): int =>
        $context->result->value()->isFeatured ? 10 : 60,
);

return $this->cached(
    fn (): Cached => Cached::of($this->products->find($productId)),
    new CachePolicy(ttl: Ttl::Auto),
    $strategy,
);
```

The context exposes:

| Property | Description |
|---|---|
| `key` | Effective key for the fetch operation |
| `result` | Successful origin `Cached` result |
| `now` | Runtime time as a Unix timestamp |

The resolver must return a non-negative TTL in seconds. The strategy only shortens an existing finite expiration; it never extends one. It does not run when a stale fallback is returned.

`Ttl::Auto` is a natural policy for a purely dynamic TTL because it inherits the expiration added by the strategy. A fixed policy can also be used and, with the default `clamp: true`, selects the earlier expiration.

## Stale If Error

`StaleIfErrorCacheStrategy` keeps entries after logical expiration and may return one when the origin fails:

```php
use Magix\Cache\Runtime\Strategy\StaleIfErrorCacheStrategy;

$strategy = new StaleIfErrorCacheStrategy(maxAge: 300);

return $this->cached(
    fn (): Cached => Cached::of($this->origin->fetch()),
    new CachePolicy(ttl: 30),
    $strategy,
);
```

With this configuration:

1. A successful result is fresh for 30 seconds.
2. Storage physically retains it for up to another 300 seconds.
3. After logical expiration, MagixCache tries the origin normally.
4. If an eligible exception occurs, the retained entry is returned.
5. After the stale window ends, the origin exception is rethrown.

By default, the strategy accepts `Exception` instances and does not catch PHP `Error` instances. Supply a classifier to select failures explicitly:

```php
$strategy = new StaleIfErrorCacheStrategy(
    maxAge: 300,
    accepts: static fn (Throwable $error): bool =>
        $error instanceof UpstreamUnavailable,
);
```

The returned stale value keeps its original logical expiration. `maxAge` must be zero or greater.

## Bypass Cache Backend Errors

`BypassCacheErrorsStrategy` keeps the origin path available when the cache backend fails:

```php
use Magix\Cache\Runtime\Strategy\BypassCacheErrorsStrategy;

$strategy = new BypassCacheErrorsStrategy();
```

For accepted failures:

- A cache read failure becomes a miss, so the origin is fetched.
- A cache write failure is ignored, so the origin result is still returned.

By default, it accepts PSR-6 and PSR-16 `CacheException` implementations. Other throwables are rethrown. Supply a classifier for a non-PSR backend:

```php
$strategy = new BypassCacheErrorsStrategy(
    accepts: static fn (Throwable $error): bool =>
        $error instanceof CacheBackendUnavailable,
);
```

This strategy does not suppress origin failures.

## Compose Strategies

Use `CompositeCacheStrategy` when a boundary needs multiple behaviors:

```php
use Magix\Cache\Runtime\Strategy\BypassCacheErrorsStrategy;
use Magix\Cache\Runtime\Strategy\CompositeCacheStrategy;
use Magix\Cache\Runtime\Strategy\StaleIfErrorCacheStrategy;

$strategy = new CompositeCacheStrategy(
    new BypassCacheErrorsStrategy(),
    new StaleIfErrorCacheStrategy(maxAge: 300),
);
```

Strategies are ordered from outermost to innermost, like HTTP middleware. For each operation, the first strategy receives the operation first and decides whether and how to call the next strategy.

Order matters when strategies transform the same operation or catch the same throwable. Keep classifiers narrow so backend errors and origin errors are handled by the intended strategy.

## Write a Custom Strategy

Extend `CacheStrategyMiddleware` when only selected phases need customization. Unchanged methods delegate automatically.

The following strategy records cache lookup duration:

```php
<?php

use Closure;
use Magix\Cache\Cache\CacheEntry;
use Magix\Cache\Runtime\Operation\CacheGet;
use Magix\Cache\Runtime\Strategy\CacheStrategyMiddleware;
use Override;
use Psr\Log\LoggerInterface;

final readonly class CacheTimingStrategy extends CacheStrategyMiddleware
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[Override]
    public function get(CacheGet $operation, Closure $next): ?CacheEntry
    {
        $startedAt = hrtime(true);

        try {
            return $next($operation);
        } finally {
            $this->logger->debug('Magix cache lookup completed', [
                'key' => $operation->key,
                'nanoseconds' => hrtime(true) - $startedAt,
            ]);
        }
    }
}
```

Implement `CacheStrategy` directly when all three phases need explicit behavior.

## Operation Types

Each phase receives an immutable operation object:

| Phase | Operation | Useful API |
|---|---|---|
| Read | `CacheGet` | `key`, `now()`, `withKey()` |
| Fetch | `OriginFetch` | `key`, `invoke()`, `stale()`, `now()`, `withKey()` |
| Write | `CacheSet` | `key`, `entry()`, `withKey()`, `withEntry()` |

Always call the supplied `$next` closure unless the strategy intentionally short-circuits that phase. Pass a modified immutable operation to `$next` rather than mutating the original.

An origin fetch returns `OriginFetchResult`, whose outcome is either `Origin` or `Stale`. Use `originValue()` only for an origin outcome and `staleEntry()` only for a stale outcome.
