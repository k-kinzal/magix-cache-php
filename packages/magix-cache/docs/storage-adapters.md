# Storage Adapters

This guide explains how to connect MagixCache to PSR-6, PSR-16, framework caches, or a custom storage topology.

## Storage Boundary

The runtime depends on `Magix\Cache\Cache\Cache`, a small port that reads and writes complete `CacheEntry` objects:

```php
interface Cache
{
    public function get(string $key, Closure $typeWitness): ?CacheEntry;

    public function set(string $key, CacheEntry $entry): void;
}
```

Application code works with public `Cached<T>` values. Only the runtime and storage boundary work with internal `CacheEntry<T>` values; do not expose `CacheEntry` from queries.

## PSR-16

Wrap any PSR-16 implementation with `SimpleCache`:

```php
<?php

use Magix\Cache\Cache\PSR16\SimpleCache as MagixSimpleCache;
use Magix\Cache\CacheRuntime;
use Psr\SimpleCache\CacheInterface;

/** @var CacheInterface $cache */
$cache = $container->get(CacheInterface::class);

CacheRuntime::setCurrent(
    new CacheRuntime(new MagixSimpleCache($cache)),
);
```

The adapter stores the complete `CacheEntry` and passes a TTL based on its physical retention deadline to the PSR-16 backend.

## PSR-6

Wrap any PSR-6 pool with `CacheItemPool`:

```php
<?php

use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Psr\Cache\CacheItemPoolInterface;

/** @var CacheItemPoolInterface $pool */
$pool = $container->get(CacheItemPoolInterface::class);

CacheRuntime::setCurrent(
    new CacheRuntime(new CacheItemPool($pool)),
);
```

The adapter sets the PSR-6 item's absolute expiration to the entry's physical retention deadline.

Both adapters treat a backend hit containing something other than a Magix `CacheEntry` as a cache miss. Use a dedicated namespace or pool when other application features could write the same key.

## Framework Integrations

The dedicated adapters connect the framework's default cache service and install the runtime:

| Framework | Package | Backend |
|---|---|---|
| Laravel 12 / 13 | [`k-kinzal/magix-cache-laravel`](../../magix-cache-laravel/README.md) | Default `cache.store` through PSR-16 |
| Symfony 7.4 / 8 | [`k-kinzal/magix-cache-symfony`](../../magix-cache-symfony/README.md) | `cache.app` through PSR-6 |

Laravel package discovery performs setup automatically. Symfony applications enable `MagixCacheBundle` in `config/bundles.php`.

## Logical Expiration and Physical Retention

Each stored entry has two deadlines:

| Field | Purpose |
|---|---|
| `expiresAt` | Logical freshness deadline; normal reads stop returning the entry after this time |
| `retainedUntil` | Physical storage deadline; the backend may retain the entry until this time |

They are equal by default. `StaleIfErrorCacheStrategy` extends `retainedUntil` without changing `expiresAt`, allowing an expired entry to remain available only as a stale fallback.

The PSR adapters use `retainedUntil` for backend expiration. The runtime still checks both deadlines, so a physically present but logically expired entry is never returned as a fresh hit.

## Stored Metadata

A `CacheEntry` preserves:

- The result value
- Absolute logical expiration
- Absolute physical retention
- Tags
- Visibility
- Diagnostic reasons

Only results with all of the following properties are written:

- `cacheable` is `true`
- Visibility is not `NoStore`
- Expiration is finite and in the future

The backend must be able to store the value and `CacheEntry` object. For cross-process caches, choose a serializer that supports the result types used by the application.

Tags are persisted as metadata, but the included PSR adapters do not invoke backend-specific tag APIs. Add a custom implementation or decorator when tag indexes and invalidation are required.

## Custom Storage

Implement `Cache` when storage is not exposed through PSR-6 or PSR-16:

```php
<?php

use Closure;
use Magix\Cache\Cache\Cache;
use Magix\Cache\Cache\CacheEntry;
use Override;

final readonly class ApplicationCache implements Cache
{
    public function __construct(private CacheBackend $backend)
    {
    }

    #[Override]
    public function get(string $key, Closure $typeWitness): ?CacheEntry
    {
        unset($typeWitness);
        $value = $this->backend->get($key);

        return $value instanceof CacheEntry ? $value : null;
    }

    #[Override]
    public function set(string $key, CacheEntry $entry): void
    {
        $this->backend->putUntil($key, $entry, $entry->retainedUntil);
    }
}
```

The `typeWitness` closure communicates the expected generic value type to static analysis and specialized implementations. A normal storage adapter does not invoke it.

Custom implementations must preserve the complete entry, retain it no later than `retainedUntil`, and return `null` for misses or incompatible values.

Use a `Cache` decorator for storage topology such as namespacing, metrics, encryption, or multiple tiers. Use `CacheKeyStrategy` when only the generated key format needs to change.

## Backend Failures

Cache backend failures are propagated by default. To treat eligible read failures as misses and eligible write failures as skipped writes, add `BypassCacheErrorsStrategy` to that cache boundary:

```php
$this->cached(
    compute: fn (): Cached => Cached::of($this->origin->fetch()),
    policy: new CachePolicy(ttl: 30),
    strategy: new BypassCacheErrorsStrategy(),
);
```

See [Cache Strategies](cache-strategies.md#bypass-cache-backend-errors) for classification and composition details.
