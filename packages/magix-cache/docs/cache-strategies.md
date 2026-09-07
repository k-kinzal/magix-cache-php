# Cache Strategies

This guide explains how to build cache behavior from reusable `CacheStrategy` classes and how the contracts they publish let the analyzer explain the composed behavior.

## Why Strategies

Attributes such as `#[StaleIfError]` cover one fixed behavior each. A strategy is the composable form of the same idea: a class that participates in the cache operations of a boundary — reading, producing, and storing — and that can be combined with other strategies into one behavior. Composing strategies yields another strategy, so a composition can be composed again.

Every strategy also publishes what it guarantees as a *contract*. The runtime executes the composition, and the CLI derives the composed contract from the same construction code, so what runs and what `magix analyze` explains come from one declaration.

## The CacheStrategy Interface

```php
interface CacheStrategy
{
    public function get(CacheOperation $operation, NextCacheStrategy $next): ?Cached;
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached;
    public function set(CacheOperation $operation, Cached $result, NextCacheStrategy $next): void;
}
```

Each operation receives the shared `CacheOperation` context — the resolved key, the clock, the single base time taken right after the origin succeeded, the stale candidate the lookup retained — and `$next`, the bound remainder of the chain. The runtime appends its own terminal strategy, which owns the fixed stage bodies: the guarded lookup, the origin call inside the stale-if-error capture range, and the re-judged store. A strategy wraps those stages; it cannot reorder them.

The composition order is meaningful: the first strategy wraps everything after it, so its pre-processing runs first, its post-processing last, and its failure capture surrounds the delegates. A strategy may also short-circuit by answering without delegating.

## Declaring a Composition

Extend `CompositeCacheStrategy` and build the composition inside a public static `create()` that takes typed arguments:

```php
final class ProductCacheStrategy extends CompositeCacheStrategy
{
    public static function create(int $min = 30): CacheStrategy
    {
        return parent::compose(
            new KeySpreadExpirationStrategy(
                minimum: $min,
                maximum: 60,
            ),
            new ProductFreshnessStrategy(
                minimum: $min,
            ),
            new StaleIfErrorCacheStrategy(
                maxAge: 300,
                accepts: static fn (Throwable $error): bool =>
                    $error instanceof UpstreamUnavailable,
            ),
        );
    }
}
```

`create()` constructs and composes; `compose()` connects the delegation; the returned value is an ordinary `CacheStrategy` that the runtime executes and that another `create()` can take as a child.

A boundary declares which composition it runs, and with which arguments:

```php
#[Cache]
#[UseStrategy(strategy: ProductCacheStrategy::class, min: 60)]
public function execute(int $productId): Cached
{
    return $this->cached(fn (): Cached => /* ... */);
}
```

The runtime resolves `ProductCacheStrategy::create(min: 60)` once per boundary declaration. A method-level `#[UseStrategy]` replaces a class-level one as a whole, and `enabled: false` disables a class-level default. The strategy class and its arguments are part of the declaration fingerprint, so changing them separates the stored entries.

## Publishing a Contract

A strategy declares the effect of its normal origin path on `fetch()`:

```php
final readonly class ProductFreshnessStrategy implements CacheStrategy
{
    public function __construct(private int $minimum) {}

    #[Ttl(min: new ConstructorArg('minimum'))]
    public function fetch(CacheOperation $operation, NextCacheStrategy $next): Cached
    {
        // any implementation that honors the contract
    }
}
```

`Contract\Ttl` is not a range annotation but a promise: on the normal origin path the strategy meets one lifetime constraint into the produced metadata, that constraint lies within the declared bounds relative to the base time, and it never extends an expiration a dependency already imposed. `new ConstructorArg('minimum')` is an explicit reference to the constructor argument — the analyzer binds it to the value the construction code passes; it never guesses from the name. A missing bound is undetermined, not unlimited, and `#[Ttl(unconstrained: true)]` declares that the operation adds no lifetime constraint at all.

The bundled strategies publish their contracts the same way: `KeySpreadExpirationStrategy` declares `min`/`max` from its constructor arguments, and `StaleIfErrorCacheStrategy` declares `unconstrained: true` because it changes nothing on the normal path.

A composition never re-declares the contracts of its children: the contract of `create()` is derived from the child contracts and the construction arguments.

## Strategies and the Fixed Stages

The runtime meets strategy constraints *before* the policy constraint, both at the single base time taken right after the origin succeeded. A boundary with `#[Cache]` (that is, `Ttl::Auto`) can therefore take its lifetime from what the strategies guarantee, and a fixed policy TTL still caps whatever the strategies choose. Nothing a strategy does can extend an expiration a dependency already imposed — every addition goes through the metadata meet.

On a failure path, a strategy such as `StaleIfErrorCacheStrategy` catches only the `RuntimeException` family — failures the origin declares as behavior — and serves the retained candidate through `CacheOperation::staleWithin()`, which applies the same retention and age judgement the runtime uses. A served candidate keeps its expired expiration and suppresses the store.

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
public static function create(int $min = 30): CacheStrategy
{
    return parent::compose(
        new ExternalTtlStrategy(),
        new KeySpreadExpirationStrategy(minimum: $min, maximum: 60),
    );
}
```

The assumption replaces exactly the one analysis item it names, is marked `(assumed)` in the output, and changes nothing at runtime. Effects it does not mention stay unanalyzed. Reusable behavior belongs in the contract of the strategy itself; the assumption only feeds the analysis of this composition.

## Contracts Are Declared, Not Proven

The analyzer derives results from contracts and construction code; it does not prove an arbitrary implementation correct, and it does not restrict how a strategy is implemented to make itself smarter. The implementer of a strategy is responsible for honoring the published contract. A declaration that cannot work as written — a reference to a constructor parameter that does not exist, a `create()` that is missing — is reported as `invalid` by `magix analyze` and by the `unresolved-strategy` lint rule, never silently corrected.
