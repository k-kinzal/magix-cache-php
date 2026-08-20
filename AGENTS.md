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

### Failure contract

Failures are split by who has to act on them, and the split is enforced by php-ai-toolkit's rules plus PHPStan's checked-exception analysis.

- `RuntimeException` and its subclasses are declared behavior: the caller is expected to handle them. `Cache\CacheBackendFailure` is what the `Cache` port declares, and `Cli\Key\CacheKeyUnresolvable` is what the key command reports. The bundled adapters translate a backend's own failures into these types and pass the original as `previous`.
- `LogicException`, `InvalidArgumentException`, and `Error` raised *by this library* mean this library or its caller is wrong. They are never expected in a test and never part of a declared behavior. Fix the origin instead.
- Every direct `throw` carries a `@throws` tag naming a concrete type, and a catch names the type it can actually handle.

Two places genuinely cannot name what they catch, because the failure comes from code this library does not own and the caller decides which failures matter. `StaleIfErrorCacheStrategy` receives whatever the caller's origin raises and `BypassCacheErrorsStrategy` whatever a caller-supplied `Cache` raises. Narrowing those to a family this library happens to like silently drops the rest — a PSR-18 client, `Doctrine\DBAL\Exception`, and `JsonException` all sit outside `RuntimeException`. Both therefore catch `Throwable`, are listed in `customRules.broadCatchAllowedPaths`, and hand the decision to a replaceable classifier rather than making it in the catch clause. Both rethrow the caller's original exception, because wrapping takes away the type the caller wants to catch; the only honest tag for that is `@throws \Throwable`, so `customRules.genericThrowsTag` is switched off for those two files in `ignoreErrors` with the reason recorded.

Everywhere else the catch names what it can actually handle, and "the caller's code might throw anything" is not a reason to widen it:

- `HashCacheKeyStrategy` catches `Exception`, which is how PHP reports a value `serialize()` refuses. An `Error` from the caller's own `__serialize()` is a bug in that method and escapes, because relabelling it as an unusable key would hide it.
- `KeyCommand` catches only `CacheKeyUnresolvable`. A reducer that throws is not its business: the console application is already the boundary that renders a failure, and it reports the file and line, which an `$io->error()` on the message alone would have thrown away.

When a toolkit rule and the specification disagree, the specification wins and the exemption is written down. But "the rule is wrong here" has to be argued from what the code must do, not from how many errors it removes.

A precondition is checked where the value enters. `CachePolicy` and `#[Cache]` take a plain `int` for a lifetime, because that is what an attribute argument can be, so both check that it is zero or greater; the same holds for `maxTtl`, the version, the tags, and a dynamically resolved TTL. Declaring `int<0, max>` in PHPDoc instead is not a substitute: it binds only a caller who runs PHPStan over their own code, and this is a library. Write the check, declare it with `@throws`, and do not write a test that asserts a rejected value is rejected.

Check it once, at the boundary, and let the types carry it from there: `CacheTokenSet` turns `list<string>` into `list<non-empty-string>`, and `CachePolicy` runs it at construction so an unusable tag fails when the policy is written rather than when the boundary first executes.

### Static analysis

`packages/magix-cache-cli` reproduces these rules without executing code. `Reader` turns syntax into declarations, `Graph` applies the composition invariants above, `Lint` reports declarations that cannot hold, and `Render` formats the result. Any change to policy resolution, key derivation, or composition must be mirrored in `EffectCalculator` and covered by a lint rule when it can fail at runtime.

Keep PSR/backend details in adapters, preserve these monotone rules, and add tests beside the owning package. Before completing a change, run `composer config:validate`, `composer packages:validate`, `composer security:audit`, `composer lint`, and `composer test`. `composer lint` runs php-cs-fixer, PHPStan with the php-ai-toolkit rules, LocGuard, TreeGuard, and Deptrac; run `composer toolkit:install` once per checkout to get the toolkit's setup skills.

`composer doc-gen` renders the contract itself: every `@throws`, the types on both sides of a boundary, the deptrac layers, and — after `composer test:coverage` — the test cases that cover each method. It is a generator, not a gate, so it stays out of `composer lint`. Read it when changing a declared failure or a public signature: if the page does not say what the change means for a caller, the PHPDoc does not either.
