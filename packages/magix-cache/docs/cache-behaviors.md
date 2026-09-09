# Cache Behaviors

This guide explains the optional behaviors a cache boundary can declare: stale-if-error fallbacks, dynamic TTLs, and cache-backend failure bypass.

## Declaring Behaviors

Behaviors are attributes, declared next to `#[Cache]` on the method or on the concrete class. A method-level attribute replaces the class-level attribute of the same kind as a whole, and parent-class attributes are never inherited implicitly. There is no per-call override path.

```php
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\StaleIfError;

#[Cache(ttl: 30)]
#[StaleIfError(maxAge: 300, exceptions: [UpstreamUnavailable::class])]
#[BypassCacheErrors]
public function execute(int $productId): Cached
{
    return $this->cached(
        fn (): Cached => Cached::of($this->origin->fetch($productId)),
    );
}
```

Each behavior applies to a fixed part of the execution, and the stage order never depends on how the attributes are written: only the origin call is inside the stale-if-error capture range, and only cache reads and writes are inside the backend bypass range.

A method disables a class-level default with `enabled: false`:

```php
#[Cache(ttl: 30)]
#[StaleIfError(maxAge: 300, exceptions: [UpstreamUnavailable::class])]
final class CatalogQueries
{
    use Cacheable;

    // Served stale on UpstreamUnavailable, like every method in this class.
    public function execute(int $productId): Cached { /* ... */ }

    // Must always reflect the origin, even during an outage.
    #[StaleIfError(enabled: false)]
    public function executeForCheckout(int $productId): Cached { /* ... */ }
}
```

Changing a behavior changes the fingerprint of the effective declaration, so entries stored under the previous declaration are no longer read. See [Cache Keys](cache-keys.md#default-key).

## Stale If Error

`#[StaleIfError]` serves a retained expired entry when the origin fails with one of the declared exception types:

```php
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\StaleIfError;

#[Cache(ttl: 30)]
#[StaleIfError(maxAge: 300, exceptions: [UpstreamUnavailable::class])]
public function execute(): Cached
{
    return $this->cached(
        fn (): Cached => Cached::of($this->origin->fetch()),
    );
}
```

With this declaration:

1. A successful result is fresh for 30 seconds (`expiresAt`).
2. Storage physically retains it for another 300 seconds (`retainedUntil = expiresAt + maxAge`).
3. After expiration, the boundary tries the origin normally.
4. When the origin fails with a declared exception type, the retained entry stands in.
5. Outside the stale window, the origin exception propagates unchanged.

At judgement time `s`, a retained entry is eligible only while `expiresAt <= s` and `s < min(retainedUntil, expiresAt + maxAge)`. An entry exactly at its retention or age limit is rejected.

The exception list is the whole contract, and it only accepts declared behavior: every declared type must be a `RuntimeException` subtype, and an empty list is rejected when the behavior is enabled. Bugs — the `LogicException` family and PHP `Error`s — are never `RuntimeException`, so they can never be answered with stale data. When an origin meets an expected outage in a foreign exception hierarchy (an HTTP client failure, a database driver exception), it translates that failure into its own declared type, the way `UpstreamUnavailable extends RuntimeException` does above. A failure that matches no declared type propagates unchanged — the caller still catches the exact exception its origin raised.

A served stale value keeps its expired expiration. A parent that composes it inherits the expired constraint through the metadata meet, so an automatic parent cannot re-store it as fresh. An explicit parent TTL may replace that expired deadline. Extending retention never changes the expiration itself; see [Storage Adapters](storage-adapters.md#logical-expiration-and-physical-retention).

`#[StaleIfError]` declares the same `StaleIfErrorCacheStrategy` available to compositions. The strategy is constructed for each invocation and owns its own candidate and reuse judgement; the runtime has no separate attribute fallback path.

`maxAge` must be zero or greater. Only the origin call is inside the capture range: a failure while reading or writing the cache never produces a stale fallback.

## Dynamic TTL

`#[DynamicTtl]` references a resolver that derives an additional lifetime constraint from each successful origin result. Implement `CacheTtlResolver`:

```php
<?php

use Magix\Cache\Runtime\Extension\CacheTtlResolver;
use Magix\Cache\Runtime\Extension\DynamicTtlContext;
use Override;

final readonly class ProductTtlResolver implements CacheTtlResolver
{
    #[Override]
    public function resolve(DynamicTtlContext $context): int
    {
        return $context->result->value()->isFeatured ? 10 : 60;
    }
}
```

Register the instance with the runtime and reference it by class name:

```php
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;

CacheRuntimeRegistry::register('default', new CacheRuntime(
    cache: $magixCache,
    ttlResolvers: [new ProductTtlResolver()],
));
```

```php
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\DynamicTtl;

#[Cache]
#[DynamicTtl(resolver: ProductTtlResolver::class)]
public function execute(int $productId): Cached
{
    return $this->cached(
        fn (): Cached => Cached::of($this->products->find($productId)),
    );
}
```

The context exposes:

| Property | Description |
|---|---|
| `key` | Effective cache key of the boundary |
| `result` | Successful origin `Cached` result |
| `now` | The same base time the policy is evaluated at, as a Unix timestamp |

The resolver must return a lifetime of zero or more seconds; a negative return value is a configuration error. The resolved lifetime replaces the policy, parameter and inherited expiration at the origin base time. A subsequent Strategy override has higher priority. The resolver runs only after a successful origin call — not on a fresh hit and not for a stale fallback.

The default `Ttl::Auto` pairs naturally with a dynamic TTL because it inherits the expiration the resolver supplied. A dynamic TTL overrides a fixed policy TTL; a later Strategy override wins over the resolver.

One boundary declares at most one resolver. Referencing a resolver that is not registered with the boundary's runtime is a definition error (`LogicException`), not a silent fallback.

## Bypass Cache Backend Errors

`#[BypassCacheErrors]` keeps the origin path available when the cache backend fails:

```php
use Magix\Cache\Attribute\BypassCacheErrors;
use Magix\Cache\Attribute\Cache;

#[Cache(ttl: 30)]
#[BypassCacheErrors]
public function execute(int $productId): Cached
{
    return $this->cached(
        fn (): Cached => Cached::of($this->products->find($productId)),
    );
}
```

For classified failures:

- A cache read failure becomes a miss, so the origin runs.
- A cache write failure becomes a skipped write, so the origin result is still returned.

Origin failures and definition errors are never classified; they always propagate.

The `Cache` port declares its failures as `CacheBackendFailure`, a `RuntimeException`, so the bypass only ever judges the `RuntimeException` family; a backend that throws outside it violates the port contract and propagates as a bug. Without an explicit classifier the runtime uses `DefaultBackendErrorClassifier`, which accepts `CacheBackendFailure` — the failure the bundled PSR adapters raise — and the PSR-6 and PSR-16 `CacheException` interfaces. A hand-written `Cache` implementation that reports failures with its own `RuntimeException` subtypes classifies them by implementing `BackendErrorClassifier`:

```php
<?php

use Magix\Cache\Runtime\Extension\BackendErrorClassifier;
use Magix\Cache\Runtime\Extension\CacheAccess;
use Override;
use RuntimeException;

final readonly class RedisFailureClassifier implements BackendErrorClassifier
{
    #[Override]
    public function isBackendFailure(RuntimeException $error, CacheAccess $access): bool
    {
        return $error instanceof RedisClusterUnavailable;
    }
}
```

Register it with the runtime and reference it from the attribute:

```php
CacheRuntimeRegistry::register('default', new CacheRuntime(
    cache: $magixCache,
    errorClassifiers: [new RedisFailureClassifier()],
));
```

```php
#[BypassCacheErrors(classifier: RedisFailureClassifier::class)]
```

`CacheAccess::Read` and `CacheAccess::Write` tell the classifier which side of the storage boundary failed. Keep classifiers narrow: accept only storage failures, never failures the origin can raise.

## Observe the Runtime

Behaviors change control flow; observation does not. Pass a `CacheObserver` to the runtime to receive diagnostic events:

```php
<?php

use Magix\Cache\Runtime\Extension\CacheEvent;
use Magix\Cache\Runtime\Extension\CacheObserver;
use Override;
use Psr\Log\LoggerInterface;

final readonly class LoggingObserver implements CacheObserver
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[Override]
    public function observe(CacheEvent $event, string $key): void
    {
        $this->logger->debug('magix-cache '.$event->name, ['key' => $key]);
    }
}
```

```php
CacheRuntimeRegistry::register('default', new CacheRuntime(
    cache: $magixCache,
    observer: new LoggingObserver($logger),
));
```

| Event | Meaning |
|---|---|
| `FreshHit` | A stored entry answered the lookup while still fresh |
| `Miss` | No fresh entry answered the lookup |
| `StaleServed` | A retained expired entry stood in for a failed origin |
| `Stored` | The computed result was persisted |
| `StoreSkipped` | The computed result was returned without being persisted |
| `CorruptEntry` | A stored entry could not be trusted and was treated as a miss |
| `BackendBypassed` | A classified backend failure was bypassed instead of propagated |

An observer sees what happened and for which key; it cannot change values, metadata, or control flow.

There is no general middleware that can replace values or metadata. Each customization has a purpose-specific contract: `CacheKeyStrategy` for key formats, `CacheTtlResolver` for per-result lifetimes, `BackendErrorClassifier` for backend failures, `CacheObserver` for diagnostics, and the `Cache` port or a decorator for storage topology. See [Storage Adapters](storage-adapters.md) and [Cache Keys](cache-keys.md).
