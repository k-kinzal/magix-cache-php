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

`#[UseStrategy]` names a class whose public static `create()` returns a `StrategyDefinition`: typed configuration in, immutable construction recipe out. With static factory arguments, only this recipe is memoized. `#[StrategyArgument('name')]` on a boundary parameter binds its current value to the active factory: in that case only the binding plan is memoized, and `create()` produces a new recipe for every invocation, including hits. A destination must be a declared non-variadic value parameter and has exactly one source; static named/positional arguments and parameter bindings cannot overlap. `StrategyDefinition::of()` describes a constructible `CacheStrategy` and its arguments; `CompositeCacheStrategy::compose()` composes definitions, and a composition composes again. The runtime constructs every executable strategy and nested child afresh for each `cached()` invocation, including nested calls and fresh hits. One execution uses the same objects across `get/fetch/set`. A strategy owns its own execution state; it is not required to be pure or reusable across invocations. Definition arguments allow values, enum cases, arrays and nested definitions, never executable objects or closures.

`CacheOperation` contains only the key and clock. `get()` exposes physically retained data through `CacheRead` (`Cached` plus physical retention), and the runtime judges freshness after the chain. `fetch()` returns `OriginResult` (value and the single origin base time), `OriginFailure` (the original declared origin exception), or `CacheAnswer` (an answer returned without origin constraints or another store). `set()` receives an immutable `CacheWrite`; retention requests are passed through this request, never shared context state. The terminal owns storage access and the origin call but knows no Stale behavior. `StaleIfErrorCacheStrategy` alone owns its candidate, exception selection, age judgement and retention policy. `#[StaleIfError]` is normalized into this same strategy next to the origin, behind the user composition; there is no parallel attribute execution path.

The runtime preserves fixed stages and applies strategy constraints before the policy at one base time, allowing `Ttl::Auto` to derive from those constraints without extending dependency expiration. Strategy contracts (`#[Ttl(new ConstructorArg('minimum'))]` on `fetch()`, omitted when the operation adds no TTL constraint) describe the normal origin path; a composition never re-declares child contracts. `#[AssumeTtl]` is analysis-only and covers exactly its named child.

`#[CacheTtl]`, `#[CacheTags]`, and `#[CacheVisibility]` on boundary parameters add invocation constraints: TTLs meet at the origin base time, tags union, and visibility meets before lookup so NoStore prevents reads. Multiple parameters compose; none replaces the static policy or upstream metadata. Bound parameters cannot be ignored or variadic, and their values are validated before lookup. A key reducer may reduce the source value, but the evaluated constraints and immutable strategy recipe also enter the key under `@configuration`. The declaration fingerprint includes binding destinations.

All method arguments form the key by default, together with the runtime namespace, the concrete class, the declaring method, the policy version, and a fingerprint of the effective declaration; use `#[CacheKey]` for stable reduction and `#[CacheIgnore]` only for values that cannot affect output.

Runtimes are registered by name in `CacheRuntimeRegistry` at bootstrap and never rebound; an unknown reference is a definition error, not a fallback. `CacheRuntime` executes a `CacheInvocation` through a stage order that never depends on attribute order. Only the origin call is inside the stale-if-error capture range, and only cache reads and writes are inside the backend bypass range. The runtime owns conversion between public `Cached<T>` and internal `CacheEntry<T>`; these types must remain independent. `expiresAt` controls freshness, `retainedUntil` controls physical retention for stale handling, and extending retention never changes the expiration.

### Composition invariants

`Cached<T>` carries an evaluated value and immutable `CacheMetadata`. `CacheMetadata::meet()` is the only way to add constraints and is a fixed law with `top()` as its identity:

- use the earliest finite expiration (`null` means unconstrained, never "unknown");
- combine cacheability with AND;
- choose the stricter visibility: `Shared < Private < NoStore`;
- union tags and diagnostic reasons.

`map()` keeps this value's metadata. `flatMap()`, `flatten()`, `zip()`, `sequence()`, `traverse()`, and `combine2()`..`combine5()` meet the metadata of every input. `flatten()` removes exactly one `Cached` layer. `unzip()` puts the whole pair's metadata on both projections. `sequence()` and `traverse()` consume iterables eagerly, preserve keys, and meet even overwritten items; empty input has `top()` metadata. `Cached` has no magic forwarding or `@mixin`: property access, method calls, array functions, iteration, and string conversion use the explicit value or a composition callback. Extracting a value with `value()` detaches it from its constraints, so a dependency obtained inside `map()` must be chained with `flatMap()` or `combineN` instead.

Policy application is pure (`PolicySemantics`) and evaluated at one base time taken right after the origin succeeds: a fixed TTL is always bounded by the upstream expiration (there is no opt-out), `Ttl::Auto` and `Ttl::FromUpstream` require a finite upstream constraint, and a dynamic TTL is an additional constraint met into the result, never a replacement. A stale entry served after an eligible origin failure keeps its expired expiration, so a parent that composes it cannot restore it fresh. Store only cacheable results with permitted visibility and a future finite expiration, re-judged immediately before the write.

Cache reuse preserves the complete evaluated metadata, including fractional absolute expiration, tags and reasons; hits never restart lifetimes or reapply origin constraints. For unchanged source results, declarations, evaluated constraints and base times, replacing any subtree with its stored result must leave all ancestor metadata equal. Protect this with the retained-subtree matrix in `tests/Integration/MetadataBubblingTest.php` in the core package, across memory and serialized PSR adapters. At different base times, recomputed relative lifetimes may differ while remaining bounded by dependency expiration; equality with a different source generation or arbitrary strategy decisions is not promised. See the core composition guide for the substitution proof and time bounds.

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

`packages/magix-cache-cli` reproduces these rules without executing code. `Reader` turns syntax into declarations, `Graph` applies the composition invariants and records analysis problems, and `Render` formats the result. The CLI exposes `analyze` and `key`; there is no separate inventory or lint command. Any change to policy resolution, key derivation, or composition must be mirrored in `EffectCalculator` and represented in the affected analysis node's problems when the declaration cannot hold.

The CLI works in a different value domain from the runtime: the runtime evaluates absolute times and real values, while the CLI propagates declared bounds and conditions as an abstract TTL estimate that keeps "known", "unconstrained", "unknown", and "invalid" apart. An unknown estimate may still carry proven bounds — `30-60s`, `30-?s` (undetermined, not unlimited), `≤60s` — and a statically unknown expiration is never converted to `CacheMetadata::top()` or to a concrete number — `Ttl::FromUpstream(maxTtl: 30)` over an unknown upstream is "at most 30s, requires a finite upstream expiration at runtime", not "30s".

The CLI reads parameter configuration without execution. Supplied arguments remain unknown even when defaults exist; parameter TTLs supply finite constraints while preserving proven bounds. Dynamic visibility and tags carry explicit uncertainty through dependencies. `ParameterEffects` checks source declarations, and `StrategyResolver` checks factory destinations; their problems are displayed by `analyze`.

When a cache boundary reaches another cache boundary through ordinary methods, `analyze` reports the path as a cache propagation analysis gap by default. The call graph cannot prove whether those methods preserve or detach metadata. The gap contributes unknown TTL, visibility and tags to the parent without assuming the child's constraints propagate, and remains separate from invalid declaration problems. Displaying ordinary calls or filtering subtrees never changes this uncertainty or removes the parent's gap diagnostics. Detection respects scanned sources, resolved calls, recursion and depth limits; an uncached entry point alone is not a gap.

`#[Ttl(30, new TtlRange(min: 600, max: 900))]` declares alternative finite constraints and renders as `30/600-900s`. `#[Ttl(30)]` declares exactly 30 seconds, while `#[Ttl(min: 600, max: 900)]` declares a range. Positional alternatives and named bounds cannot be mixed; use `TtlRange` inside an alternatives list. The `oneOf` and `unconstrained` options are removed. An omitted or empty `#[Ttl]` on a readable strategy means it adds no TTL constraint; an unreadable class or factory stays unknown. `#[AssumeTtl]` supports the same lifetime syntax after its named child; supplying only the child explicitly assumes no added constraint. The CLI retains a normalized union of intervals, composes them by pairwise minimum images, and preserves gaps through dependencies, policies, and every renderer. It never takes a convex envelope unless uncertainty actually fills those gaps, never substitutes set intersection for lifetime composition, and never assumes correlations between the predicates selecting different strategies' alternatives. The proof of a finite expiration propagates independently of whether its numeric TTL is known, including to automatic parent policies. Invalid alternatives and missing references are reported as strategy problems by `analyze`.

`#[ExpiresAt('12:00', until: '12:15', timezone: 'Asia/Tokyo')]` on `fetch()` declares a finite daily wall-clock candidate independently of TTL seconds. Omit `until` for a point; an earlier end crosses the local date boundary. Repeat the attribute on one `fetch()` for multiple times/windows; source and reflected readers retain every declaration and validate/bind each independently. Constructor references bind actual construction values, including unknown invocation parameters. The strategy owns occurrence selection, distribution, rollover, and DST behavior. CLI analysis preserves clock fields through nested strategies, dependencies, policies, and all renderers; it never invents seconds from its own clock or assumes a day is 86400 seconds. Multiple clock contracts are simultaneous constraints whose selected expirations meet, not intersected local windows. `strategy at` is the candidate and `expires by` is a propagated upper constraint; shorter TTLs and upstream metadata still win. A valid clock contract proves finite expiration for automatic parent policies. Missing references and malformed declarations are strategy problems. An omitted TTL adds no relative duration constraint but must not hide an explicit `ExpiresAt` contract.

For strategies the CLI analyzes the same declarations the runtime executes: `StrategyReader` reads a strategy class into its constructor and `create()` parameters, its composed construction definitions, and its contract attributes; `StrategyResolver` binds the `#[UseStrategy]` arguments, follows `StrategyDefinition::of()` and composed definitions, resolves each `ConstructorArg`/`Arg` reference against the bound construction (a reference to a missing parameter is a declaration error, reported in the analysis node's strategy problems), and meets the child contracts into a candidate constraint. The candidate a strategy chooses and the effective lifetime after dependencies and policy stay two separate outputs. The bundled strategies outside the scanned sources are read through their reflected contract attributes without executing user code; a `create()` body the analysis cannot follow stays unknown unless an explicit `#[AssumeTtl]` covers that one child.

Keep PSR/backend details in adapters, preserve these monotone rules, and add tests beside the owning package. Before completing a change, run `composer config:validate`, `composer packages:validate`, `composer security:audit`, `composer lint`, and `composer test`. `composer lint` runs php-cs-fixer, PHPStan with the php-ai-toolkit rules, LocGuard, TreeGuard, and Deptrac; run `composer toolkit:install` once per checkout to get the toolkit's setup skills.

`composer doc-gen` renders the contract itself: every `@throws`, the types on both sides of a boundary, the deptrac layers, and — after `composer test:coverage` — the test cases that cover each method. It is a generator, not a gate, so it stays out of `composer lint`. Read it when changing a declared failure or a public signature: if the page does not say what the change means for a caller, the PHPDoc does not either.
