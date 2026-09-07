# Parameter Configuration

A boundary can declare that its method parameters supply cache constraints or
arguments to the active strategy factory. The parameter attribute identifies the
source directly, so renaming that boundary parameter does not break the binding.

```php
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheTags;
use Magix\Cache\Attribute\CacheTtl;
use Magix\Cache\Attribute\CacheVisibility;
use Magix\Cache\Attribute\StrategyArgument;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

#[Cache(ttl: 60, tags: ['catalog'])]
#[UseStrategy(ProductCacheStrategy::class)]
public function fetch(
    int $productId,
    #[CacheTtl] int $ttl = 30,
    #[CacheTags] array $tags = [],
    #[CacheVisibility] Visibility $visibility = Visibility::Shared,
    #[StrategyArgument('min')] int $minimum = 10,
): Cached {
    return $this->cached(
        fn (): Cached => Cached::of($this->products->find($productId)),
    );
}
```

`ProductCacheStrategy::create()` in [Cache Strategies](cache-strategies.md)
accepts `min`. Here it receives the current invocation's `$minimum`, including
the method's default when omitted. Positional and named calls behave the same.
`cached()` still takes exactly one closure; the binding comes from attributes.
The values are captured when the boundary enters `cached()`.

## Cache constraints

| Parameter attribute | Required value | Composition |
| --- | --- | --- |
| `#[CacheTtl]` | Non-negative `int` seconds | Earliest expiration wins |
| `#[CacheTags]` | `list<string>` of valid cache tags | Tags are unioned with policy and dependency tags |
| `#[CacheVisibility]` | A `Metadata\Visibility` enum value | The stricter visibility wins |

These attributes add constraints. With `#[Cache(ttl: 60)]` and a parameter TTL of
30 seconds the result has at most 30 seconds; a dependency expiring in 20 seconds
still caps it at 20 seconds. All lifetime constraints use the same origin base
time. A zero TTL expires immediately and is not stored. `#[Cache]`, whose policy
is `Ttl::Auto`, can derive its expiration from a `CacheTtl` parameter alone.
`Ttl::FromUpstream` can also derive from it, keeping its required `maxTtl` cap.
A `DynamicTtl` resolver adds another constraint at that same time.

Visibility and tags are bound before lookup. `Visibility::Shared` cannot relax
an existing private policy or scope; `Visibility::NoStore` prevents both cache
reads and writes. `CacheVisibility` supplies an invocation value, while the
existing `CacheScope` declares a fixed visibility restriction.

Several parameters may supply TTLs, tags or visibility; all their constraints
are combined. Each attribute appears at most once on a given parameter. One
parameter can supply both a cache constraint and a compatible strategy argument:

```php
#[CacheTtl]
#[StrategyArgument('min')]
int $ttl,
```

Values are checked when bound, before lookup: TTLs must be non-negative integers,
tags must be lists of valid string tokens, and visibility must be the enum.
Invalid values raise `InvalidArgumentException` as caller errors; they are not
covered by stale fallback or backend bypass.

## Strategy arguments

`#[StrategyArgument('min')]` names a destination on the active
`UseStrategy` class's public static `create()` method. It supplies exactly that
argument and retains the factory's native parameter type checking. Other factory
arguments can still come from `UseStrategy` or from the factory's defaults.
The effective `UseStrategy` is selected using the existing method-over-class
precedence, before the parameter bindings are resolved.

A destination must be a declared, non-variadic parameter passed by value.
A destination has exactly one source. A parameter binding conflicts with either
a named or positional value already supplied by `UseStrategy`, and two boundary
parameters cannot supply the same destination. These are declaration errors.
A binding without an enabled `UseStrategy` is also an error, including when a
method disables a class-level strategy.

When a strategy has parameter bindings, `create()` runs once per invocation,
including fresh hits and nested calls. Only its static binding plan is memoized;
the recipe containing invocation values is not retained. Without parameter
bindings, the existing static recipe remains memoized. In both cases executable
strategies and every nested child are freshly constructed for each invocation.
The returned `StrategyDefinition` retains its existing immutable-configuration
contract: configuration values, enum cases, arrays and nested definitions,
without executable objects, closures or resources.

## Cache keys and declarations

Configuration parameters cannot use `CacheIgnore`, and variadic boundary
parameters cannot supply configuration. These restrictions are checked when the
static declaration is resolved. Ordinary unannotated variadic parameters keep
working as before.

A `CacheKey` reducer may still reduce a configuration parameter. The key also
includes its evaluated cache constraints and the resolved strategy construction
definition under the reserved `@configuration` argument, so a reducer cannot
merge entries with different effective settings. Custom key strategies must
preserve this component along with the other identity fields.

Changing a parameter's binding changes the declaration fingerprint. All bound
values are invocation-local, and cache hits keep their stored absolute expiration;
reading an entry never restarts its TTL.

## Static analysis

The CLI reads the same parameter annotations without calling the method or its
strategy factory. It displays each parameter's destinations and propagates
strategy arguments through construction definitions and contract references.
A supplied parameter is runtime-dependent even if the boundary or factory has
a default; the analyzer never substitutes that default for every invocation.

A parameter TTL satisfies the requirement for a finite constraint in `Auto` and
`FromUpstream`, while its value stays unknown. Proven policy and dependency caps
survive. For example, a dynamic TTL with a 60-second policy is bounded by 60
seconds, and a dependency capped at 20 seconds narrows it further.

Dynamic visibility is shown as the proven restriction `or stricter`; dynamic
tags are shown separately from known tags as `+ runtime tags`. JSON exposes
`visibilityUnknown` and `tagsUnknown`, and these flags propagate to parents.

`invalid-parameter-binding` reports ignored or variadic sources, repeated
attributes, incompatible constraint types, duplicate destinations and missing
active strategies. `unresolved-strategy` reports destinations that do not exist
or conflict with the factory configuration. Actual runtime values still undergo
the same boundary validation as any other library input.
