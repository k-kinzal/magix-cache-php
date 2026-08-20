# AGENTS

## Vision

Magix Cache is a library designed to optimize caching during PHP SSR (Server-Side Rendering).
By specifying Magix Cache during data retrieval, you can easily achieve optimized caching, specifically for read model caches and page caches such as CDN and browser caching.

The core philosophy of Magix Cache is multi-stage cache composition, which optimizes what to cache and how long to cache it.
For example, in a FooBarQuery that retrieves both Foo and Bar, if Foo has a 20s cache and Bar has a 60s cache, FooBarQuery will compose these two caches to result in a 20s cache.
It also optimizes other cache-related elements, such as the cache sharing scope and the presence or absence of a cache.

The fundamental concept is to provide an easy interface for users while offering a simple and highly extensible mechanism at its core.

## Architecture

This repository is a Composer monorepo with a framework-independent core and separate integration packages.

### Boundaries

- `packages/magix-cache` contains the core and depends only on PSR contracts.
- `packages/magix-cache-laravel` and `packages/magix-cache-symfony` are framework adapters that connect each framework's cache service to Magix Cache.
- `packages/magix-cache-cli` is a development tool that reads a project statically and reports its cache boundaries; it depends on the core but nothing depends on it.
- The root `Magix\Cache` namespace is reserved for `Cacheable`, `Cached`, `CachePolicy`, and `CacheRuntime`.
- `Attribute` declares optional policy/key metadata, `Composition` combines cached values, `Runtime` owns execution and metadata rules, and `Cache` owns storage contracts and PSR adapters.
- Use `CacheKeyStrategy` for key formats, `CacheStrategyMiddleware` for get/fetch/set behavior, and the `Cache` port or a decorator for storage topology.

### Execution flow

```text
query -> Cacheable -> key/policy resolution -> CacheRuntime
      -> CacheStrategy (get -> fetch -> set) -> Cache -> PSR adapter
```

`Cacheable` alone inspects the caller and attributes. Explicit `CachePolicy` takes precedence over `#[Cache]`. All method arguments form the key by default; use `#[CacheKey]` for stable reduction and `#[CacheIgnore]` only for values that cannot affect output.

`CacheRuntime` receives the resolved key and policy, then runs cache get, origin fetch, and cache set through one per-boundary `CacheStrategy`. It owns conversion between public `Cached<T>` and internal `CacheEntry<T>`; these types must remain independent. `expiresAt` controls freshness, while `retainedUntil` controls physical retention for stale handling.

### Composition invariants

`Cached<T>` carries a value and immutable `CacheMetadata`. Composition must only make constraints stricter:

- use the earliest finite expiration;
- combine cacheability with AND;
- choose the stricter visibility: `Shared < Private < NoStore`;
- union tags and diagnostic reasons.

`CachePolicy` declares constraints; `CacheStrategy` controls operations. Fixed TTLs are clamped to upstream expiration by default, and `Ttl::Auto` inherits a finite upstream constraint. Store only cacheable results with permitted visibility and a future finite expiration.

### Static analysis

`packages/magix-cache-cli` reproduces these rules without executing code. `Reader` turns syntax into declarations, `Graph` applies the composition invariants above, `Lint` reports declarations that cannot hold, and `Render` formats the result. Any change to policy resolution, key derivation, or composition must be mirrored in `EffectCalculator` and covered by a lint rule when it can fail at runtime.

Keep PSR/backend details in adapters, preserve these monotone rules, and add tests beside the owning package. Before completing a change, run `composer config:validate`, `composer packages:validate`, `composer security:audit`, `composer lint`, and `composer test`.
