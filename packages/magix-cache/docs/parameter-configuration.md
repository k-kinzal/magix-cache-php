# Parameter Configuration

A boundary can declare that its method parameters supply cache overrides or
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

## Cache overrides

| Parameter attribute | Required value | Effect |
| --- | --- | --- |
| `#[CacheTtl]` | Non-negative `int` seconds | Replaces expiration at the origin base time |
| `#[CacheTags]` | `list<string>` of valid tags | Replaces tags; `[]` clears them |
| `#[CacheVisibility]` | A `Metadata\Visibility` enum value | Replaces visibility, including Shared |

With `#[Cache(ttl: 60)]`, a parameter TTL of 90 replaces both that policy TTL
and a dependency's remaining 20 seconds. A final zero TTL prevents storage.
Parameters can supply an automatic boundary's finite expiration. A dynamic TTL
runs next, then Strategies; each explicit writer can replace the earlier value.
All relative lifetimes use one origin base time.

Visibility and tags are bound before lookup. Shared can replace a private
policy or scope. NoStore skips reads; final NoStore metadata prevents writes.
CacheVisibility supplies invocation data, while CacheScope declares fixed
parameter visibility. Unannotated fields inherit the policy and dependencies.

When several parameters supply the same field, the last in method declaration
order wins, regardless of named-call argument order. Every supplied value is
validated. Each attribute appears at most once on a parameter. One parameter
can supply a field and a compatible strategy argument:

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

A parameter TTL proves finite expiration, but its value remains unknown even
when a default exists. It replaces previous policy and dependency bounds.
Dynamic TTL behaves the same way; a known Strategy override can replace either.

Parameter visibility and tags replace earlier known fields with uncertainty.
JSON exposes `visibilityUnknown` and `tagsUnknown`; an inheriting parent carries
them forward, while an explicit parent field replaces that uncertainty.

`magix analyze` reports ignored or variadic sources, repeated attributes,
incompatible constraint types, duplicate destinations, missing active strategies,
and destinations that do not exist or conflict with the factory configuration
in the affected node's problems. Actual runtime values still undergo
the same boundary validation as any other library input.
