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
| `ttl` | `int\|Ttl` | `Ttl::Auto` | Selects the boundary expiration |
| `maxTtl` | `?int` | `null` | Upper bound for `Ttl::FromUpstream` (required in that mode) |
| `tags` | `list<string>` | `[]` | Adds cache invalidation or response tags |
| `visibility` | `Visibility` | `Visibility::Shared` | Restricts where the result may be stored |
| `version` | `string` | `'1'` | Changes the generated cache key |
| `runtime` | `string` | `'default'` | Name of a runtime registered at bootstrap |

The same constraint options are carried by the shared `CachePolicy` value type, which `#[Cache]` converts to internally. A policy only ever adds constraints: no setting can extend an expiration a dependency already imposed, or relax cacheability, visibility, tags, or reasons.

## Fixed TTL

An integer TTL is relative to the base time taken right after the origin result is produced:

```php
#[Cache(ttl: 60)]
```

A fixed TTL is always bounded by the upstream expiration; there is no opt-out. If a dependency has 20 seconds left, a 60-second boundary still expires after 20 seconds.

A TTL of `0` is valid, but its expiration is not in the future, so the result is returned without being stored and the next call executes the origin again.

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

The automatic policy requires a finite expiration from the returned `Cached` value or another constraint. Returning `Cached::of($value)` with no finite constraint is a definition error and the runtime throws a `LogicException`.

A `#[DynamicTtl]` resolver can supply the finite expiration before the automatic policy is applied. See [Cache Behaviors](cache-behaviors.md#dynamic-ttl).

## Upstream TTL

`Ttl::FromUpstream` inherits an absolute expiration supplied in the returned metadata and caps it with `maxTtl`:

```php
use Magix\Cache\Metadata\CacheMetadata;
use Magix\Cache\Runtime\Policy\Ttl;

#[Cache(ttl: Ttl::FromUpstream, maxTtl: 300)]
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

The effective expiration is the earlier of the upstream expiration and `now + maxTtl`. `maxTtl` is required for this mode, and like `Ttl::Auto`, a missing finite upstream expiration is a definition error at runtime.

## Tags

Policy tags are unioned with tags from every dependency:

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

The order is `Shared < Private < NoStore`; a parent boundary cannot loosen a dependency's visibility. A boundary whose effective visibility is `NoStore` skips the cache lookup entirely and always executes the origin.

Declare a boundary-wide restriction in the policy:

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

`#[CacheScope]` defaults to `Visibility::Private`. The visibility a scoped parameter imposes is folded into the policy with the meet, so the method declaration cannot relax it.

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

Uncacheable metadata sets `cacheable` to `false`, visibility to `NoStore`, and retains the reason. Once introduced, later composition or policies cannot make the result cacheable again.

## Cache Version

The version is part of the default cache key. A fingerprint of the effective declaration is also part of the key, so changing the TTL, tags, visibility, behaviors, or key attributes already moves the boundary to new entries. Change the version when the declaration is unchanged but a deployment changes the meaning or serialized shape of the value:

```php
#[Cache(ttl: 300, version: 'product-v3')]
```

Versions must be non-empty strings. Changing the version leaves old backend entries in place until their physical expiration; it only moves new reads and writes to a different key.

## Constraints From Parameters

Use `#[CacheTtl]`, `#[CacheTags]`, and `#[CacheVisibility]` on method parameters
when callers supply these values. Their constraints compose with this policy
and dependencies through the same fixed meet law. See
[Parameter Configuration](parameter-configuration.md) for binding, validation,
key behavior and static analysis.
