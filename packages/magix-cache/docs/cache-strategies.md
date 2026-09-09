# Cache Strategies

This guide explains how to build cache behavior from reusable `CacheStrategy` classes and how the contracts they publish let the analyzer explain the composed behavior.

## Why Strategies

Attributes such as `#[StaleIfError]` cover one fixed behavior each. A strategy is the composable form of the same idea: a class that participates in the cache operations of a boundary — reading, producing, and storing — and that can be combined with other strategies into one behavior. Composing strategies yields another strategy, so a composition can be composed again.

Every strategy also publishes what it guarantees as a *contract*. The runtime executes the composition, and the CLI derives the composed contract from the same construction code, so what runs and what `magix analyze` explains come from one declaration.

## The CacheStrategy Interface

```php
interface CacheStrategy
{
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?CacheRead;
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer;
    public function set(CacheOperation $operation, CacheWrite $request, NextCacheStrategy $next): void;
}
```

Each `cached()` call constructs a fresh strategy composition, including every nested child. The same objects participate in that call's `get`, `fetch`, and `set`; a later call or a nested call gets different objects. Strategies may keep execution state in their own properties. The library does not require them to be pure or to support concurrent reuse of one object.

`CacheOperation` supplies only the resolved key and the clock. It carries no feature state, stale candidates, retention requests, or mutable store flags. The stage protocol is explicit:

- `get()` returns a `CacheRead` containing `Cached` and `retainedUntil`, or null. Physically retained expired entries are visible to strategies. The runtime judges freshness **after** the chain returns.
- `fetch()` returns `OriginResult` (successful `Cached` and the single `baseTime`), `OriginFailure` (the original `RuntimeException`), or `CacheAnswer` (an answer returned without origin constraints or a new store). Use `OriginResult::constrain()` to meet strategy constraints into a normal result. Preserve failures and answers when a strategy has no applicable behavior.
- `set()` receives a `CacheWrite` containing the final `Cached` and an optional physical retention deadline. `retainUntil()` returns a new request with the greater deadline; it changes neither the value nor expiration. The terminal rechecks storage eligibility immediately before writing.

The runtime appends an internal terminal which reads storage, calls the origin, and writes storage. It captures only the origin's `RuntimeException` into `OriginFailure`; errors from strategy code, the clock, reads, writes, or policy evaluation are not origin failures. If no strategy answers the failure, the runtime rethrows the original exception object.

The composition order is meaningful: the first strategy wraps everything after it, so its pre-processing runs first, its post-processing last, and its failure capture surrounds the delegates. A strategy may also short-circuit by answering without delegating.

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

A strategy declares the effect of its normal origin path on `fetch()`:

```php
final readonly class ProductFreshnessStrategy implements CacheStrategy
{
    public function __construct(private int $minimum) {}

    #[Ttl(new ConstructorArg('minimum'))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): OriginResult|OriginFailure|CacheAnswer
    {
        $result = $next->fetch($operation);

        return $result instanceof OriginResult
            ? $result->constrain(CacheMetadata::forTtl($this->minimum, $result->baseTime))
            : $result;
    }
}
```

`Contract\Ttl` is not a range annotation but a promise: on the normal origin path the strategy meets one lifetime constraint into the produced metadata, that constraint lies within the declared bounds relative to the base time, and it never extends an expiration a dependency already imposed. `new ConstructorArg('minimum')` is an explicit reference to the constructor argument — the analyzer binds it to the value the construction code passes; it never guesses from the name. A missing bound is undetermined, not unlimited. Omitting both `#[Ttl]` and `#[ExpiresAt]` declares that a readable strategy adds no expiration constraint on the normal origin path; an empty `#[Ttl]` means the same thing. The analyzer does not infer an undeclared TTL from the method body. A class or factory the analyzer cannot read remains unknown.

The bundled strategies publish their contracts the same way: `KeySpreadExpirationStrategy` declares `min`/`max` from its constructor arguments, and `StaleIfErrorCacheStrategy` omits `#[Ttl]` because it adds no lifetime constraint on the normal path.

A composition never re-declares the contracts of its children: the contract of `create()` is derived from the child contracts and the construction arguments.

## Alternative Lifetimes

Use a fixed positional value, named bounds for a single range, or multiple positional alternatives:

| Declaration on `fetch()` | Candidate lifetime |
| --- | --- |
| `#[Ttl(30)]` | Exactly 30 seconds |
| `#[Ttl(min: 600, max: 900)]` | Between 600 and 900 seconds |
| `#[Ttl(30, 60)]` | Either 30 or 60 seconds |
| Attribute omitted | Adds no TTL constraint |

```php
use Magix\Cache\Strategy\Contract\Ttl;
use Magix\Cache\Strategy\Contract\TtlRange;

// On fetch(): normally 30 seconds, or between 600 and 900 seconds.
#[Ttl(30, new TtlRange(min: 600, max: 900))]
```

The CLI renders this candidate as `30/600-900s`. A point is an integer; a range has inclusive `min` and `max` bounds. Equal bounds describe a point. A missing bound is still undetermined: `#[Ttl(30, new TtlRange(min: 600))]` renders as `30/600-?s`. Every alternative promises a finite TTL constraint on the normal origin path, even when its numeric upper bound cannot be determined statically. Positional alternatives cannot be combined with named `min` or `max`; put a `TtlRange` in the alternatives when a range is needed. No `oneOf` or `unconstrained` option is needed or accepted.

Constructor references can appear as points or within ranges:

```php
#[Ttl(
    30,
    60,
    new TtlRange(min: 600, max: 900),
    new TtlRange(new ConstructorArg('minimum'), new ConstructorArg('maximum')),
)]
```

`#[AssumeTtl(ExternalStrategy::class, 30, new TtlRange(min: new Arg('minimum'), max: 900))]` supports the same alternatives for its one named child, with references bound to `create()` arguments. `#[AssumeTtl(ExternalStrategy::class)]` explicitly assumes that an opaque child adds no TTL constraint; omitting the assumption leaves an unreadable child unknown.

The strategy's `fetch()` implementation selects the lifetime and meets it through `OriginResult::constrain(CacheMetadata::forTtl($ttl, $result->baseTime))`. For time-dependent behavior, evaluate the time window there using the origin base time and the intended timezone. Static-argument `create()` definitions are memoized; they must not freeze a current-time decision into the construction recipe. Fresh cache hits retain their existing expiration and do not run `fetch()`.

Alternatives are carried through dependencies and parent policies without filling their gaps:

| Parent policy over a `30/600-900s` dependency | Effective TTL |
| --- | --- |
| `Ttl::Auto` | `30/600-900s` |
| Fixed `700`, or `Ttl::FromUpstream` with `maxTtl: 700` | `30/600-700s` |
| Fixed `300` | `30/300s` |
| Fixed `20` | `20s` |

Composition takes the minimum for every pair of alternatives; it does not intersect the sets. For example, composing `30/600-900s` with `60/700-800s` produces `30/60/600-800s`. Duplicate and overlapping intervals are normalized. An unknown dependency that could expire at any earlier time can fill the gaps, producing `≤900s`; this uncertainty is preserved. The proof of a finite expiration also propagates, so an automatic parent does not report a missing-upstream notice merely because the exact TTL is runtime-dependent.

The contracts describe possible outcomes, not the predicates selecting them. The analyzer does not prove time-window conditions or correlations between strategies. If two strategies share the same condition, their possible combinations are conservatively analyzed independently. Runtime metadata still uses the same fixed meet law.

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

This is an analysis contract for an existing `fetch()` implementation. It promises that every successful normal origin path adds a finite expiration at a future occurrence of the local time or within the declared window. It does not schedule eviction or implement a timer. The strategy selects the occurrence and the point within the window (for example, by a stable cache-key hash), defines rollover and daylight-saving behavior for missing/repeated local times, and meets the absolute expiration into `OriginResult` metadata. Evaluate that choice against `OriginResult::baseTime` in `fetch()`, since `create()` definitions with static arguments are memoized. Fresh hits keep their existing expiration; stale answers keep their expired metadata.

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

You may declare both `#[Ttl(...)]` and `#[ExpiresAt(...)]` on a `fetch()` that adds both constraints; the analyzer meets them. Nested compositions retain every candidate time/window, including different timezones, and describe their earliest selected expiration without intersecting the windows or sorting local clock faces. Supplied invocation values stay unknown even when their boundary parameter has a default. Invalid times, timezones, argument shapes, and missing constructor references appear in `analyze` problems.

Tree and Mermaid output include the time constraints. JSON adds `strategy.expirations`, per-step `expirations`, and `effective.expirationConstraints` for timed boundaries. Each item preserves `at`, `until`, `timezone`, `window`, `crossesMidnight`, and `recurrence: "daily"`; unresolved clock fields are `null`. The `window` flag records a declared end that may still be unresolved. These are simultaneous candidate constraints, not a prediction that storage will remain fresh until the displayed time. Existing TTL JSON fields retain their duration semantics.

## Strategies and the Fixed Stages

The runtime meets strategy constraints *before* the policy constraint, both at the single base time taken right after the origin succeeded. A boundary with `#[Cache]` (that is, `Ttl::Auto`) can therefore take its lifetime from what the strategies guarantee, and a fixed policy TTL still caps whatever the strategies choose. Nothing a strategy does can extend an expiration a dependency already imposed — every addition goes through the metadata meet.

`StaleIfErrorCacheStrategy` owns the candidate it observes in `get()`. When `fetch()` receives an accepted `OriginFailure`, it checks the candidate's expiration, physical retention, and `maxAge` at the current failure time. It returns a `CacheAnswer` with the original expired metadata. Its `set()` extends only the write request's retention. No part of this feature lives in `CacheOperation` or the terminal.

`#[StaleIfError]` is declaration syntax for this same strategy, placed next to the origin behind the user's composition. It has no separate fallback execution path. An optional diagnostic name on `CacheAnswer` is reported when recognized by the runtime; the built-in strategy supplies `StaleServed`.

## Migrating an Existing Strategy

Change `create(): CacheStrategy` to `create(): StrategyDefinition` and each constructed child from `new Child(...)` to `StrategyDefinition::of(Child::class, ...)`. Update `get/fetch/set` to the protocol above. Replace the bundled stale strategy's `accepts` closure argument with its `exceptions` list; custom decisions belong in a custom Strategy's `fetch()` implementation.

Move invocation-local fields such as a fallback candidate into the Strategy. Remove calls to `retainStale()`, `stale()`, `staleWithin()`, `suppressStore()`, and `extendRetention()` on `CacheOperation`. Use the successful result's `baseTime`, the explicit `CacheAnswer`, and the write request's `retainUntil()` instead. An origin failure is now an `OriginFailure` result; catch around delegation only for declared failures from the delegate itself.

## What the Analyzer Shows

`magix analyze` binds the `#[UseStrategy]` arguments to `create()`, follows the composed constructions in order, binds each contract reference, and meets the child contracts:

```text
PromotedProductQuery::seasonal
  strategy     ProductCacheStrategy::create(min: 30)
               - KeySpreadExpirationStrategy  30-60s
               - ProductFreshnessStrategy  30-?s
               - StaleIfErrorCacheStrategy  unconstrained
  strategy ttl 30-60s
  ttl          30-60s
```

The `strategy ttl` row is the candidate constraint the composition adds; the `ttl` row is the effective lifetime once dependencies and the policy are applied. A dependency with a 20-second expiration shortens the effective lifetime to `20s` while the candidate stays `30-60s`; an upstream that is only known at runtime keeps the effective lifetime as `≤60s` with the condition spelled out. `?` means undetermined, never unlimited, and an unknown is never converted into a default or into "no constraint".

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
