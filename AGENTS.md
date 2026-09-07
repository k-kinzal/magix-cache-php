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
- `Metadata` owns the constraint value type and its fixed meet law and depends on nothing else. `Attribute` declares policies and behaviors, `Composition` combines cached values, `Strategy` owns the composable strategy contract (`CacheStrategy`, `CompositeCacheStrategy`, the operation context, and the `Contract` attributes), `Runtime` owns declaration resolution, execution stages, the runtime registry, and extension contracts, and `Cache` owns storage contracts and PSR adapters.
- Use `CacheKeyStrategy` for key formats, the extension contracts in `Runtime\Extension` (`CacheTtlResolver`, `BackendErrorClassifier`, `CacheObserver`) for purpose-specific behavior, and the `Cache` port or a decorator for storage topology. There is no general middleware that can replace values or metadata.

### Execution flow

```text
query -> Cacheable::cached(fn) -> attribute declaration resolution -> CacheInvocation
      -> CacheRuntimeRegistry -> CacheRuntime fixed stages
         (lookup -> fresh-hit judgement -> origin -> constraints at one base time -> re-judged store)
      -> GuardedCache -> Cache -> PSR adapter
```

`cached()` takes exactly one closure and is the anti-corruption layer between PHP and the internal model: it captures the call site, resolves the static declaration, and delegates. Policy, behaviors, and the runtime reference come from attributes alone — `#[Cache]` on the method wins over the concrete class as a whole, behaviors (`#[StaleIfError]`, `#[DynamicTtl]`, `#[BypassCacheErrors]`, `#[UseStrategy]`) follow the same precedence and are disabled with `enabled: false`, and parent-class attributes are never inherited implicitly. There is no per-call override path.

`#[UseStrategy]` names a class whose public static `create()` returns a `StrategyDefinition`: typed configuration in, immutable construction recipe out. Only this recipe is memoized. `StrategyDefinition::of()` describes a constructible `CacheStrategy` and its arguments; `CompositeCacheStrategy::compose()` composes definitions, and a composition composes again. The runtime constructs every executable strategy and nested child afresh for each `cached()` invocation, including nested calls and fresh hits. One execution uses the same objects across `get/fetch/set`. A strategy owns its own execution state; it is not required to be pure or reusable across invocations. Definition arguments allow values, enum cases, arrays and nested definitions, never executable objects or closures.

`CacheOperation` contains only the key and clock. `get()` exposes physically retained data through `CacheRead` (`Cached` plus physical retention), and the runtime judges freshness after the chain. `fetch()` returns `OriginResult` (value and the single origin base time), `OriginFailure` (the original declared origin exception), or `CacheAnswer` (an answer returned without origin constraints or another store). `set()` receives an immutable `CacheWrite`; retention requests are passed through this request, never shared context state. The terminal owns storage access and the origin call but knows no Stale behavior. `StaleIfErrorCacheStrategy` alone owns its candidate, exception selection, age judgement and retention policy. `#[StaleIfError]` is normalized into this same strategy next to the origin, behind the user composition; there is no parallel attribute execution path.

The runtime preserves fixed stages and applies strategy constraints before the policy at one base time, allowing `Ttl::Auto` to derive from those constraints without extending dependency expiration. Strategy contracts (`#[Ttl(min: new ConstructorArg('minimum'))]` on `fetch()`, `unconstrained: true` for adds nothing) describe the normal origin path; a composition never re-declares child contracts. `#[AssumeTtl]` is analysis-only and covers exactly its named child.

All method arguments form the key by default, together with the runtime namespace, the concrete class, the declaring method, the policy version, and a fingerprint of the effective declaration; use `#[CacheKey]` for stable reduction and `#[CacheIgnore]` only for values that cannot affect output.

Runtimes are registered by name in `CacheRuntimeRegistry` at bootstrap and never rebound; an unknown reference is a definition error, not a fallback. `CacheRuntime` executes a `CacheInvocation` through a stage order that never depends on attribute order. Only the origin call is inside the stale-if-error capture range, and only cache reads and writes are inside the backend bypass range. The runtime owns conversion between public `Cached<T>` and internal `CacheEntry<T>`; these types must remain independent. `expiresAt` controls freshness, `retainedUntil` controls physical retention for stale handling, and extending retention never changes the expiration.

### Composition invariants

`Cached<T>` carries an evaluated value and immutable `CacheMetadata`. `CacheMetadata::meet()` is the only way to add constraints and is a fixed law with `top()` as its identity:

- use the earliest finite expiration (`null` means unconstrained, never "unknown");
- combine cacheability with AND;
- choose the stricter visibility: `Shared < Private < NoStore`;
- union tags and diagnostic reasons.

`map()` keeps this value's metadata, `flatMap()` and `combine2()`..`combine5()` meet the metadata of every input. Extracting a value with `value()` detaches it from its constraints, so a dependency obtained inside `map()` must be chained with `flatMap()` or `combineN` instead.

Policy application is pure (`PolicySemantics`) and evaluated at one base time taken right after the origin succeeds: a fixed TTL is always bounded by the upstream expiration (there is no opt-out), `Ttl::Auto` and `Ttl::FromUpstream` require a finite upstream constraint, and a dynamic TTL is an additional constraint met into the result, never a replacement. A stale entry served after an eligible origin failure keeps its expired expiration, so a parent that composes it cannot restore it fresh. Store only cacheable results with permitted visibility and a future finite expiration, re-judged immediately before the write.

### Failure contract

Failures are split by who has to act on them, and the split is enforced by php-ai-toolkit's rules plus PHPStan's checked-exception analysis.

- `RuntimeException` and its subclasses are declared behavior: the caller is expected to handle them. `Cache\CacheBackendFailure` is what the `Cache` port declares, and `Cli\Key\CacheKeyUnresolvable` is what the key command reports. The bundled adapters translate a backend's own failures into these types and pass the original as `previous`.
- `LogicException`, `InvalidArgumentException`, and `Error` raised *by this library* mean this library or its caller is wrong. They are never expected in a test and never part of a declared behavior. Fix the origin instead.
- Every direct `throw` carries a `@throws` tag naming a concrete type, and a catch names the type it can actually handle.

The two failure-handling behaviors follow the same split instead of escaping it. `#[StaleIfError]` only accepts declared behavior: every declared exception type must be a `RuntimeException` subtype, so the terminal captures exactly `RuntimeException` around the origin call into `OriginFailure`. The Stale strategy alone decides whether to answer it; `CacheRuntime` rethrows the original exception when no strategy does — a bug (`LogicException` family, `Error`) never enters the catch and can never be answered with stale data. An origin that meets an expected outage in a foreign hierarchy — a PSR-18 client, `Doctrine\DBAL\Exception`, `JsonException` — translates it into its own declared `RuntimeException` subtype at the boundary, which is the same translation duty the bundled adapters already perform for storage. Likewise `GuardedCache` catches exactly `RuntimeException` around cache reads and writes, because `CacheBackendFailure` is what the `Cache` port declares; the `#[BypassCacheErrors]` classifier decides within that family, and a backend that reports outside it violates the port contract and propagates as the bug it is. Neither boundary needs a broad catch, a generic `@throws` tag, or an `ignoreErrors` entry.

Everywhere else the catch names what it can actually handle, and "the caller's code might throw anything" is not a reason to widen it:

- `HashCacheKeyStrategy` catches `Exception`, which is how PHP reports a value `serialize()` refuses. An `Error` from the caller's own `__serialize()` is a bug in that method and escapes, because relabelling it as an unusable key would hide it.
- `KeyCommand` catches only `CacheKeyUnresolvable`. A reducer that throws is not its business: the console application is already the boundary that renders a failure, and it reports the file and line, which an `$io->error()` on the message alone would have thrown away.

When a toolkit rule and the specification disagree, the specification wins and the exemption is written down. But "the rule is wrong here" has to be argued from what the code must do, not from how many errors it removes.

A precondition is checked where the value enters. `CachePolicy` and `#[Cache]` take a plain `int` for a lifetime, because that is what an attribute argument can be, so both check that it is zero or greater; the same holds for `maxTtl`, the version, the tags, and a dynamically resolved TTL. Declaring `int<0, max>` in PHPDoc instead is not a substitute: it binds only a caller who runs PHPStan over their own code, and this is a library. Write the check, declare it with `@throws`, and do not write a test that asserts a rejected value is rejected.

Check it once, at the boundary, and let the types carry it from there: `CacheTokenSet` turns `list<string>` into `list<non-empty-string>`, and `CachePolicy` runs it at construction so an unusable tag fails when the policy is written rather than when the boundary first executes.

### Static analysis

`packages/magix-cache-cli` reproduces these rules without executing code. `Reader` turns syntax into declarations, `Graph` applies the composition invariants above, `Lint` reports declarations that cannot hold, and `Render` formats the result. Any change to policy resolution, key derivation, or composition must be mirrored in `EffectCalculator` and covered by a lint rule when it can fail at runtime.

The CLI works in a different value domain from the runtime: the runtime evaluates absolute times and real values, while the CLI propagates declared bounds and conditions as an abstract TTL estimate that keeps "known", "unconstrained", "unknown", and "invalid" apart. An unknown estimate may still carry proven bounds — `30-60s`, `30-?s` (undetermined, not unlimited), `≤60s` — and a statically unknown expiration is never converted to `CacheMetadata::top()` or to a concrete number — `Ttl::FromUpstream(maxTtl: 30)` over an unknown upstream is "at most 30s, requires a finite upstream expiration at runtime", not "30s".

For strategies the CLI analyzes the same declarations the runtime executes: `StrategyReader` reads a strategy class into its constructor and `create()` parameters, its composed construction definitions, and its contract attributes; `StrategyResolver` binds the `#[UseStrategy]` arguments, follows `StrategyDefinition::of()` and composed definitions, resolves each `ConstructorArg`/`Arg` reference against the bound construction (a reference to a missing parameter is a declaration error, reported by the `unresolved-strategy` lint rule), and meets the child contracts into a candidate constraint. The candidate a strategy chooses and the effective lifetime after dependencies and policy stay two separate outputs. The bundled strategies outside the scanned sources are read through their reflected contract attributes without executing user code; a `create()` body the analysis cannot follow stays unknown unless an explicit `#[AssumeTtl]` covers that one child.

Keep PSR/backend details in adapters, preserve these monotone rules, and add tests beside the owning package. Before completing a change, run `composer config:validate`, `composer packages:validate`, `composer security:audit`, `composer lint`, and `composer test`. `composer lint` runs php-cs-fixer, PHPStan with the php-ai-toolkit rules, LocGuard, TreeGuard, and Deptrac; run `composer toolkit:install` once per checkout to get the toolkit's setup skills.

`composer doc-gen` renders the contract itself: every `@throws`, the types on both sides of a boundary, the deptrac layers, and — after `composer test:coverage` — the test cases that cover each method. It is a generator, not a gate, so it stays out of `composer lint`. Read it when changing a declared failure or a public signature: if the page does not say what the change means for a caller, the PHPDoc does not either.
