# Cache Policies

This guide explains how cache boundaries declare TTL, tags, visibility, and key versions.

## Declaring a Policy

Use `#[Cache]` on a method for the common case:

```php
use Magix\Cache\Attribute\Cache;

#[Cache(ttl: 30, tags: ['products'], version: '2')]
public function execute(int $productId): Cached
{
    return $this->cached(
        fn (): Cached => Cached::of($this->products->find($productId)),
    );
}
```

The attribute can also be placed on the concrete class as a default for every method that calls `cached()`:

```php
#[Cache(ttl: 30, tags: ['catalog'])]
final class CatalogQueries
{
    use Cacheable;

    // The class policy applies unless a method declares its own #[Cache].
}
```

A method-level `#[Cache]` wins over the class-level one as a whole; the two are never mixed per option. Attributes on a parent class are never inherited implicitly, and declarations are static — there is no per-call override path. If neither the method nor its concrete class declares `#[Cache]`, `cached()` throws a `LogicException`.

`#[Cache]` accepts these options:

| Option | Type | Default | Purpose |
|---|---|---|---|
| `ttl` | `int\|Ttl` | `Ttl::Auto` | Fixed lifetime, or `Ttl::Auto` to declare none |
| `tags` | `?list<string>` | `null` | Inherits when omitted; an explicit list replaces tags, including `[]` |
| `visibility` | `?Visibility` | `null` | Inherits when omitted; an explicit enum replaces visibility |
| `version` | `string` | `'1'` | Changes the generated cache key |
| `runtime` | `string` | `'default'` | Name of a runtime registered at bootstrap |

The shared `CachePolicy` value type has the same options. Bubbling and overrides are separate: composition gathers dependency metadata, then explicitly configured boundary fields replace the inherited fields.

Priority is `dependency metadata → policy → parameter settings → dynamic TTL → Strategy`, with the later writer winning for each field. Unspecified fields inherit. Cacheability and reasons remain unchanged unless a Strategy explicitly replaces them.

## Fixed TTL

An integer TTL is relative to the base time taken right after the origin result is produced:

```php
#[Cache(ttl: 60)]
```

A fixed TTL replaces the inherited expiration. If a dependency has 20 seconds left, `#[Cache(ttl: 60)]` makes the parent expire 60 seconds after its origin succeeds. This is an intentional parent cache policy. Omit `ttl` to keep the dependency's deadline instead.

A final TTL of `0` expires immediately and prevents storage. A higher-priority parameter, dynamic TTL or Strategy may explicitly replace a policy TTL of zero.

## Automatic TTL

Use `#[Cache]` without arguments when a parent only needs to bubble up its children's cache constraints:

```php
#[Cache]
public function execute(int $productId): Cached
{
    return $this->cached(function () use ($productId): Cached {
        $product = $this->products->execute($productId);
        $inventory = $this->inventory->execute($productId);

        return $product
            ->combine2($inventory)
            ->map(
                static fn (Product $product, Inventory $inventory): ProductPage =>
                    new ProductPage($product, $inventory),
            );
    });
}
```

The composed expiration, cacheability, visibility, tags, and diagnostic reasons are preserved. The omitted TTL defaults to `Ttl::Auto`, so writing `#[Cache(ttl: Ttl::Auto)]` explicitly has the same effect. Other options can be supplied independently, such as `#[Cache(tags: ['product-pages'])]`.

`Ttl::Auto` adds no constraint of its own, so it never validates anything: the result is whatever the composition produced. A boundary that composes no finite expiration simply stores nothing, exactly like an origin that returns `Cached::of($value)` with no metadata. This is not a definition error.

An expiration can equally come from the returned metadata itself, which is how an upstream deadline enters composition:

```php
use Magix\Cache\Metadata\CacheMetadata;

#[Cache]
public function execute(): Cached
{
    return $this->cached(function (): Cached {
        $response = $this->client->fetch();

        return Cached::of(
            $response->value,
            new CacheMetadata(expiresAt: $response->expiresAt),
        );
    });
}
```

To bound such a deadline, declare the bound as the policy: `#[Cache(ttl: 300)]` replaces it outright. A parameter, `#[DynamicTtl]` resolver or Strategy can also supply or replace the expiration. See [Cache Behaviors](cache-behaviors.md#dynamic-ttl).

## Tags

Policy tags replace the inherited tag list. Omit tags to inherit; use `tags: []` to clear them:

```php
#[Cache(ttl: 30, tags: ['products', 'product:42'])]
```

Tags are deduplicated and sorted. They must be non-empty and contain only letters, digits, `_`, `.`, `:`, or `-`, which keeps them safe to forward through HTTP headers.

The core PSR adapters preserve tags in each entry but do not call backend-specific tag invalidation APIs. Use a custom `Cache` implementation or decorator when storage-level tag invalidation is required.

## Visibility

Visibility becomes more restrictive as results are composed:

| Visibility | Meaning |
|---|---|
| `Visibility::Shared` | May be stored by shared caches such as a CDN |
| `Visibility::Private` | May be stored when the key identifies the private variant |
| `Visibility::NoStore` | Must not be read from or written to storage |

Dependency bubbling uses `Shared < Private < NoStore`. Explicit parent visibility replaces the inherited choice, so Shared can override Private. Visibility resolved before lookup as NoStore skips reads; final NoStore metadata prevents writes. Strategy overrides run during fetch and cannot retroactively enable a skipped lookup.

Declare a boundary-wide override in the policy:

```php
use Magix\Cache\Metadata\Visibility;

#[Cache(ttl: 30, visibility: Visibility::Private)]
```

Use `#[CacheScope]` when a parameter introduces the restriction. The parameter remains part of the key, so entries stay separated per principal:

```php
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Metadata\Visibility;

#[Cache(ttl: 30)]
public function execute(
    #[CacheScope(Visibility::Private)] int $viewerId,
    int $productId,
): Cached {
    // The result is private, and viewerId differentiates cache entries.
}
```

`#[CacheScope]` defaults to `Visibility::Private`. Scoped visibility overrides the static policy. When multiple parameters carry scopes, the last in declaration order wins; invocation-bound `CacheVisibility` and Strategy overrides take precedence afterward.

An ignored parameter cannot also be scoped unless its scope is `NoStore`. This special combination lets an unkeyable value force execution without storage:

```php
use Closure;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Metadata\Visibility;

public function execute(
    #[CacheIgnore]
    #[CacheScope(Visibility::NoStore)]
    Closure $load,
): Cached {
    return $this->cached(fn (): Cached => Cached::of($load()));
}
```

## Uncacheable Results

An origin or dependency can explicitly prohibit storage and record a diagnostic reason:

```php
use Magix\Cache\Metadata\CacheMetadata;

return Cached::of(
    $value,
    CacheMetadata::uncacheable('authorization-dependent'),
);
```

Uncacheable metadata sets `cacheable` to `false`, visibility to `NoStore`, and retains the reason. Bubbling preserves both prohibitions. A TTL-only policy leaves them intact. A Strategy can explicitly override cacheability and visibility when that is its intended behavior; clearing a diagnostic reason alone does not enable storage.

## Cache Version

The version is part of the default cache key. A fingerprint of the effective declaration is also part of the key, so changing the TTL, tags, visibility, behaviors, or key attributes already moves the boundary to new entries. Change the version when the declaration is unchanged but a deployment changes the meaning or serialized shape of the value:

```php
#[Cache(ttl: 300, version: 'product-v3')]
```

Versions must be non-empty strings. Changing the version leaves old backend entries in place until their physical expiration; it only moves new reads and writes to a different key.

## Overrides From Parameters

Use `#[CacheTtl]`, `#[CacheTags]`, and `#[CacheVisibility]` on method parameters
when callers supply these values. They replace the corresponding policy and
inherited fields; the last annotated parameter wins per field. See
[Parameter Configuration](parameter-configuration.md) for binding, validation,
key behavior and static analysis.
