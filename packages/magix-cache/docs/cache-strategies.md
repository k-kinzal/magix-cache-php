# Cache Strategies

This guide explains how to build cache behavior from reusable `CacheStrategy` classes and how the contracts they publish let the analyzer explain the composed behavior.

## Why Strategies

Attributes such as `#[StaleIfError]` cover one fixed behavior each. A strategy is the composable form of the same idea: a class that participates in the cache operations of a boundary — reading, producing, and storing — and that can be combined with other strategies into one behavior. Composing strategies yields another strategy, so a composition can be composed again.

Every strategy also publishes what it guarantees as a *contract*. The runtime executes the composition, and the CLI derives the composed contract from the same construction code, so what runs and what `magix analyze` explains come from one declaration.

## The CacheStrategy Interface

```php
use Magix\Cache\Async\Promise;

interface CacheStrategy
{
    public function get(string $key, Closure $next): ?CacheRead;
    public function fetch(string $key, Closure $next): Promise;
    public function set(string $key, CacheWrite $request, Closure $next): void;
}
```

Each `cached()` or `asyncCached()` call constructs a fresh strategy composition, including every nested child. The same objects participate in that call's `get`, `fetch`, and `set`; a later call or a nested call gets different objects. Strategies may keep execution state in their own properties. The library does not require them to be pure or to support concurrent reuse of one object.

Each `$next` is an ordinary Closure for that operation:

| Middleware | `$next` type | Delegation |
| --- | --- | --- |
| `get` | `Closure(string): ?CacheRead` | `$next($key)` |
| `fetch` | `Closure(): Promise<Cached<mixed>>` | `$next()` |
| `set` | `Closure(string, CacheWrite): void` | `$next($key, $request)` |

`get()` can rewrite the lookup key and the returned `CacheRead` (Cached plus physical retention). The runtime judges freshness afterward. `set()` can rewrite the key and immutable `CacheWrite`, or decline the write. `retainUntil()` changes physical retention only; the runtime rechecks storage eligibility at the actual write. Fetch receives the boundary key for key-dependent metadata rules, such as spreading expirations. Its delegated inquiry takes no arguments.

`ComposedCacheStrategy` nests these closures itself and implements the same interface. `new ComposedCacheStrategy()` and `StrategyDefinition::compose()` with no children are identities: they call the supplied operation unchanged. With no declared Strategy, Runtime calls its actual read, origin inquiry and write directly. Those operations do not implement `CacheStrategy`.

For fetch, composition means `A(B(origin))`: A delegates to B, B delegates to the origin closure, and the result returns through B then A. Runtime invokes the composed fetch on a miss. A fresh hit skips it. Returning without delegating skips the remainder of the inquiry; runtime does not call the origin separately. Every resolved Cached enters the same set chain, registered with `then`.

The origin is an argument-free closure whose inputs are captured normally:

```php
return $this->cached(fn (): Cached => Cached::of($this->client->fetch($id)));
```

The fetch key identifies the cache boundary; it is not passed to the origin callback. Clock and observation dependencies are constructor parameters, separate from operation data. There is no acquired value or TTL base time in the fetch input.

Rejection handling is ordinary Promise continuation code around the delegated inquiry. `Promise::call($next)->recover(...)` also includes synchronous delegate failures. It covers the inner middleware and the actual inquiry, according to composition order. Runtime does not turn exceptions into another result type or run a separate recovery operation. A returned Cached has no source or store-control flag.

## Declaring a Composition

Extend `CompositeCacheStrategy` and build the composition inside a public static `create()` that takes typed arguments:

```php
final class ProductCacheStrategy extends CompositeCacheStrategy
{
    public static function create(int $min = 30): StrategyDefinition
    {
        return parent::compose(
            StrategyDefinition::of(
                KeySpreadExpirationStrategy::class,
                minimum: $min,
                maximum: 60,
            ),
            StrategyDefinition::of(
                ProductFreshnessStrategy::class,
                minimum: $min,
            ),
            StrategyDefinition::of(
                StaleIfErrorCacheStrategy::class,
                maxAge: 300,
                exceptions: [UpstreamUnavailable::class],
            ),
        );
    }
}
```

`create()` returns immutable construction definitions. `StrategyDefinition::of()` declares a class and its constructor arguments; `compose()` connects definitions in delegation order. A definition composes again. The runtime constructs the executable objects with `new` at each invocation. No executable Strategy or factory closure is cached. Configuration accepts scalar values, null, enum cases, arrays (up to 64 levels), and nested `StrategyDefinition` values; mutable objects and closures are rejected. Nested definitions are constructed afresh even when passed as constructor dependencies. Strategies can create their own execution resources in their constructors.

At execution, missing constructor parameters typed `Psr\Clock\ClockInterface` or `Magix\Cache\Observation\CacheObserver` receive the runtime's clock and observer. This also applies to nested definitions. The dependency objects are supplied only when constructing the executable strategy; they never enter the memoized definition. Directly constructed middleware can receive these dependencies normally. `StrategyDefinition::instantiate($factory)` also accepts a construction closure for standalone use; the closure receives the class and resolved constructor arguments and returns one `CacheStrategy`.

A boundary declares which composition it runs, and with which arguments:

```php
#[Cache]
#[UseStrategy(strategy: ProductCacheStrategy::class, min: 60)]
public function execute(int $productId): Cached
{
    return $this->cached(fn (): Cached => /* ... */);
}
```

With static arguments, the declaration resolver calls `ProductCacheStrategy::create(min: 60)` once to memoize its construction definition. A method parameter annotated with `#[StrategyArgument('min')]` instead supplies the value for each invocation; its factory runs on every invocation, including hits. See [Parameter Configuration](parameter-configuration.md). Executable instances are created for every invocation, including cache hits. A method-level `#[UseStrategy]` replaces a class-level one as a whole, and `enabled: false` disables a class-level default. The strategy class and its arguments are part of the declaration fingerprint, so changing them separates the stored entries.

## Publishing a Contract

A strategy declares the metadata effect of its `fetch()` result. This excerpt shows the fetch implementation; `get()` and `set()` delegate as described above:

```php
final readonly class ProductFreshnessStrategy implements CacheStrategy
{
    public function __construct(
        private int $minimum,
        private Psr\Clock\ClockInterface $clock = new Magix\Cache\Clock\SystemClock(),
    ) {}

    #[Ttl(new ConstructorArg('minimum'))]
    public function fetch(string $key, Closure $next): Promise
    {
        return $next()->then(fn (Cached $result): Cached => Cached::of(
            $result->value(),
            $result->metadata->withExpiration((float) $this->clock->now()->format('U.u') + $this->minimum),
        ));
    }
}
```

`Contract\Ttl` is not a range annotation but a promise: on every successful `fetch()` return the strategy overrides expiration with a lifetime in the declared bounds relative to the time chosen by the implementation. It may shorten or extend a dependency or policy deadline. `new ConstructorArg('minimum')` is an explicit reference to the constructor argument — the analyzer binds it to the value the construction code passes; it never guesses from the name. A missing bound is undetermined, not unlimited. Omitting both `#[Ttl]` and `#[ExpiresAt]` declares that a readable strategy preserves expiration on every successful `fetch()` return; an empty `#[Ttl]` means the same thing. The analyzer does not infer an undeclared TTL from the method body. A class or factory the analyzer cannot read remains unknown.

The bundled strategies publish their contracts the same way: `KeySpreadExpirationStrategy` declares `min`/`max` from its constructor arguments, and `StaleIfErrorCacheStrategy` omits `#[Ttl]` because it does not override TTL in `fetch()`.

A composition never re-declares the contracts of its children: the contract of `create()` is derived from the child contracts and the construction arguments.

## Alternative Lifetimes

Use a fixed positional value, named bounds for a single range, or multiple positional alternatives:

| Declaration on `fetch()` | Candidate lifetime |
| --- | --- |
| `#[Ttl(30)]` | Exactly 30 seconds |
| `#[Ttl(min: 600, max: 900)]` | Between 600 and 900 seconds |
| `#[Ttl(30, 60)]` | Either 30 or 60 seconds |
| Attribute omitted | Preserves TTL |

```php
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\Contract\TtlRange;

// On fetch(): normally 30 seconds, or between 600 and 900 seconds.
#[Ttl(30, new TtlRange(min: 600, max: 900))]
```

The CLI renders this candidate as `30/600-900s`. A point is an integer; a range has inclusive `min` and `max` bounds. Equal bounds describe a point. A missing bound is still undetermined: `#[Ttl(30, new TtlRange(min: 600))]` renders as `30/600-?s`. Every alternative promises a finite TTL constraint on every successful `fetch()` return, even when its numeric upper bound cannot be determined statically. Positional alternatives cannot be combined with named `min` or `max`; put a `TtlRange` in the alternatives when a range is needed. No `oneOf` or `unconstrained` option is needed or accepted.

Constructor references can appear as points or within ranges:

```php
#[Ttl(
    30,
    60,
    new TtlRange(min: 600, max: 900),
    new TtlRange(new ConstructorArg('minimum'), new ConstructorArg('maximum')),
)]
```

`#[AssumeTtl(ExternalStrategy::class, 30, new TtlRange(min: new Arg('minimum'), max: 900))]` supports the same alternatives for its one named child, with references bound to `create()` arguments. `#[AssumeTtl(ExternalStrategy::class)]` explicitly assumes that an opaque child does not override TTL; omitting the assumption leaves an unreadable child unknown.

The strategy's `fetch()` continuation selects a lifetime and returns `Cached::of($result->value(), $result->metadata->withExpiration((float) $this->clock->now()->format('U.u') + $ttl))`. For time-dependent behavior, evaluate the time window there using its injected clock and the intended timezone. Static-argument `create()` definitions are memoized; they must not freeze a current-time decision into the construction recipe. Fresh cache hits retain their existing expiration and do not run `fetch()`.

Alternatives are carried through dependencies and parent policies without filling their gaps:

| Parent policy over a `30/600-900s` dependency | Effective TTL |
| --- | --- |
| `Ttl::Auto` | `30/600-900s` |
| Fixed `700` | `700s` |
| Fixed `300` | `300s` |
| Fixed `20` | `20s` |

Dependency bubbling takes the minimum for every pair of alternatives; it does not intersect the sets. For example, composing `30/600-900s` with `60/700-800s` produces `30/60/600-800s`. Duplicate and overlapping intervals are normalized. An unknown dependency that could expire at any earlier time can fill the gaps, producing `≤900s`; this uncertainty is preserved. The proof of a finite expiration also propagates, so an automatic parent stays provably storable even when the exact TTL is runtime-dependent.

The contracts describe possible outcomes, not the predicates selecting them. The analyzer does not prove time-window conditions or correlations between strategies. Conditions are not inferred. Dependency bubbling combines alternatives by pairwise minimum; ordered Strategy overrides select the winning writer's alternatives.

The earlier positional range syntax changes meaning: `Ttl(30)` now means exactly 30 seconds, and `Ttl(30, 600)` means two alternatives. Use `Ttl(min: 30)` for a lower bound or `Ttl(min: 30, max: 600)` for a range. Replace `Ttl(oneOf: [...])` with positional alternatives and remove `Ttl(unconstrained: true)`. Apply the same migration after the strategy argument of `AssumeTtl`.

## Daily Expiration Times and Distribution Windows

Use `Contract\ExpiresAt` when the strategy selects a wall-clock expiration:

```php
use Magix\Cache\Strategy\Contract\ConstructorArg;
use Magix\Cache\Strategy\Contract\ExpiresAt;

// On fetch(): a daily expiration at noon UTC.
#[ExpiresAt('12:00')]

// On fetch(): distribute expiration times within this inclusive local window.
#[ExpiresAt('12:00', until: '12:15', timezone: 'Asia/Tokyo')]

// On fetch(): bind the actual construction values, including invocation arguments.
#[ExpiresAt(
    new ConstructorArg('at'),
    until: new ConstructorArg('until'),
    timezone: new ConstructorArg('timezone'),
)]
```

`at` and `until` accept zero-padded `HH:MM` or `HH:MM:SS` local times. Omit `until`, or bind it to `null`, for a single time. An end before the start crosses midnight: `23:55`–`00:15` ends on the following local date and renders with `(+1 day)`. Equal endpoints describe a single instant, not a full-day window. `timezone` is an IANA identifier and defaults to `UTC`, independently of the process timezone. Each field can reference a declared constructor parameter with `ConstructorArg`; `Arg` belongs to factory assumptions and cannot bind here.

This is an analysis contract for an existing `fetch()` implementation. It promises that every successful `fetch()` return adds a finite expiration at a future occurrence of the local time or within the declared window. It does not schedule eviction or implement a timer. The strategy selects the occurrence and the point within the window (for example, by a stable cache-key hash), defines rollover and daylight-saving behavior for missing/repeated local times, and replaces expiration using `Cached::of($result->value(), $result->metadata->withExpiration($deadline))`. Evaluate that choice using `(float) $this->clock->now()->format('U.u')` in `fetch()`, since `create()` definitions with static arguments are memoized. Fresh hits keep their existing expiration. An outer expiration writer also processes an inner middleware's fallback answer.

Repeat `#[ExpiresAt]` on the same `fetch()` to declare multiple daily times or windows:

```php
#[ExpiresAt('09:00', timezone: 'Asia/Tokyo')]
#[ExpiresAt('12:00', until: '12:15', timezone: 'Asia/Tokyo')]
#[ExpiresAt('18:00', timezone: 'Asia/Tokyo')]
```

Each declaration on this one fetch contributes a simultaneous promise about the expiration it selects. Separate strategies override in return order. A strategy that chooses the next occurrence of each point can use this to describe several daily cutoffs. Each window keeps its own end, timezone, and constructor references. The CLI displays `earliest of (daily 09:00 Asia/Tokyo; daily 12:00-12:15 Asia/Tokyo; daily 18:00 Asia/Tokyo)` and preserves each declaration in JSON. Repeating the attribute does not declare mutually exclusive branches or change the strategy implementation. Existing single declarations keep their syntax and behavior.

The CLI shows the candidate times separately from durations:

```text
  strategy     DailyExpirationStrategy::create(at: '12:00', until: '12:15', timezone: 'Asia/Tokyo')
               - DailyExpirationStrategy  expires daily 12:00-12:15 Asia/Tokyo
  strategy ttl unknown (duration depends on the origin time and daily expiration)
  strategy at  daily 12:00-12:15 Asia/Tokyo
  ttl          unknown (duration depends on the origin time and daily expiration)
  expires by   daily 12:00-12:15 Asia/Tokyo
```

`strategy at` describes the candidate selected by the strategy. `expires by` carries the time constraints through dependencies and parent policies; the result can expire sooner because of TTLs, dynamic constraints, or other dependencies. A fixed parent TTL of 60 seconds therefore remains `≤60s`, while retaining the time window. `Ttl::Auto` and automatic parents retain proof of a finite expiration without guessing a number of seconds. No current-time calculation, 24-hour upper bound, distribution algorithm, or correlation between different strategies is inferred.

You may declare both `#[Ttl(...)]` and `#[ExpiresAt(...)]` on a `fetch()` whose selected expiration satisfies both contracts; the analyzer combines those promises. Nested strategy steps retain every candidate time/window, but only the outermost expiration writer survives in the effective result. Dependency bubbling takes the earliest selected expiration without intersecting windows or sorting clock faces. Supplied invocation values stay unknown even when their boundary parameter has a default. Invalid times, timezones, argument shapes, and missing constructor references appear in `analyze` problems.

Tree and Mermaid output include the time constraints. JSON adds `strategy.expirations`, per-step `expirations`, and `effective.expirationConstraints` for timed boundaries. Each item preserves `at`, `until`, `timezone`, `window`, `crossesMidnight`, and `recurrence: "daily"`; unresolved clock fields are `null`. The `window` flag records a declared end that may still be unresolved. These are simultaneous candidate constraints, not a prediction that storage will remain fresh until the displayed time. Existing TTL JSON fields retain their duration semantics.

## Strategies and the Fixed Stages

Priority is `bubbled origin metadata → policy → parameter settings → dynamic TTL → Strategy`. The runtime inquiry invokes the argument-free origin closure, records one base time after success, and applies local policy, parameter and dynamic settings. It returns the resulting Cached through the middleware call stack. In `compose(A, B)`, B returns first and A writes last; the outermost explicit writer wins. A Strategy composition does not meet expiration values.

To replace expiration with 60 seconds from this middleware's clock read:

```php
return Cached::of(
    $result->value(),
    $result->metadata->withExpiration((float) $this->clock->now()->format('U.u') + 60),
);
```

This keeps the payload and every other metadata field. To replace other fields explicitly:

```php
return Cached::of(
    $result->value(),
    $result->metadata->withTags(['replacement'])->withVisibility(Visibility::Shared),
);
```

To add a tag deliberately, pass the combined tag list to `withTags()`. An empty list clears the field. `Cached::of()` can also receive an entirely different metadata value; the strategy must declare every field it replaces. A value-only transformation can use `$result->map(...)`, which keeps metadata. An implementation must preserve the value type promised by the boundary. No explicit override implicitly bubbles another dependency.

Policy, parameter and dynamic TTL share the runtime's origin-success timestamp. Middleware owns its own timing: the bundled key-spread strategy reads the clock after its delegate returns. Storage eligibility is always rechecked against the current clock.

`StaleIfErrorCacheStrategy` records a retained candidate in `get()`. Its fetch is ordinary middleware:

```php
return Promise::call($next)->recover(function (RuntimeException $error): Cached {
    // Select an eligible candidate using this strategy's own state,
    // or rethrow the same exception when it cannot answer.
});
```

It checks configured exception types, expiration, physical retention and maximum age. It returns the selected Cached with its stored metadata. If that exact object reaches `set()`, it notifies its injected Observer with `CacheEvent::StaleServed` and suppresses its write. A replacement from an outer middleware goes through ordinary retention and storage handling. These decisions belong to StaleIfError alone.

Order matters for both exceptions and returned values. `compose(StaleIfError, TtlWriter)` lets the stale middleware answer a failure from the inner inquiry without running the TTL writer's return code. `compose(TtlWriter, StaleIfError)` lets the outer writer replace the expiration of a stale answer and potentially store it anew. An exception raised by an outer middleware is outside an inner stale middleware's catch. A RuntimeException raised by local settings in the runtime inquiry is inside that catch.

`#[StaleIfError]` places this strategy behind the user composition. There is no separate attribute recovery path. A different middleware may catch a declared exception and produce a fresh replacement, which returns through outer middleware and can be stored normally.

## Implementing Fetch Middleware

Forward an inquiry with `return $next();`. Transform the resolved Cached inside `then`, using `map()` or explicit metadata replacements. Short-circuit with `Promise::resolved(Cached::of($replacement))`. Use `Promise::call($next)->recover(...)` for declared runtime failures, including later rejections. The Strategy interface still has only get/fetch/set; recovery is a Promise continuation. See [Asynchronous Cached Values](async-cached.md).

## What the Analyzer Shows

`magix analyze` binds the `#[UseStrategy]` arguments to `create()`, follows the composed constructions in order, binds each contract reference, and applies expiration overrides in fetch return order:

```text
PromotedProductQuery::seasonal
  strategy     ProductCacheStrategy::create(min: 30)
               - KeySpreadExpirationStrategy  30-60s
               - ProductFreshnessStrategy  30-?s
               - StaleIfErrorCacheStrategy  unconstrained
  strategy ttl 30-60s
  ttl          30-60s
```

The `strategy ttl` row is the winning Strategy expiration override; `ttl` is the effective lifetime. In this example both remain `30-60s` even over a 20-second dependency or a fixed policy. Each child candidate stays visible in the strategy steps. An opaque outer override makes effective expiration unknown; a known outer override can replace an opaque inner estimate. `?` means undetermined, never unlimited, and an unknown is never converted into a default or into "no constraint".

## Skipping Analysis Explicitly

When a composed child cannot be analyzed — an external factory, a class outside the scanned sources — declare the assumption on `create()`:

```php
#[AssumeTtl(strategy: ExternalTtlStrategy::class, min: new Arg('min'), max: 300)]
public static function create(int $min = 30): StrategyDefinition
{
    return parent::compose(
        StrategyDefinition::of(ExternalTtlStrategy::class),
        StrategyDefinition::of(KeySpreadExpirationStrategy::class, minimum: $min, maximum: 60),
    );
}
```

The assumption replaces exactly the one analysis item it names, is marked `(assumed)` in the output, and changes nothing at runtime. Effects it does not mention stay unanalyzed. Reusable behavior belongs in the contract of the strategy itself; the assumption only feeds the analysis of this composition.

## Contracts Are Declared, Not Proven

The analyzer derives results from contracts and construction code; it does not prove an arbitrary implementation correct, and it does not restrict how a strategy is implemented to make itself smarter. The implementer of a strategy is responsible for honoring the published contract. A declaration that cannot work as written — a reference to a constructor parameter that does not exist, a missing `create()`, an executable factory result, or a definition containing mutable objects — is reported as `invalid` by `magix analyze`, never silently corrected.


`Contract\Ttl` and `Contract\ExpiresAt` describe expiration only. `fetch()` can also return a `Cached` with replaced visibility, tags and cacheability, and `Contract\WritesMetadata` is how a strategy declares that:

```php
#[Ttl(min: new ConstructorArg('minimum'), max: new ConstructorArg('maximum'))]
#[WritesMetadata(visibility: true)]
public function fetch(string $key, Closure $next): Promise
```

Omitting the attribute declares that every one of those fields is preserved, the same promise an omitted `#[Ttl]` makes about expiration. Supplying it with no arguments declares that all three are replaced with values the declaration cannot describe, so the analyzer reports them as unknown. Naming fields declares exactly those, and the fields left out stay as precise as the dependencies made them.

This is a contract, not an inference: a `fetch()` that writes a field it did not name breaks its own contract, exactly as one that overrides expiration without declaring `#[Ttl]` does. The bundled `KeySpreadExpirationStrategy` and `StaleIfErrorCacheStrategy` declare nothing here because they preserve those fields. A later parent can explicitly replace unknown fields; `AssumeTtl` only resolves expiration, never other metadata.
