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

The attribute can also be placed on a class as a fallback for every method that calls `cached()`:

```php
#[Cache(ttl: 30, tags: ['catalog'])]
final class CatalogQueries
{
    use Cacheable;

    // The class policy applies unless this method declares its own #[Cache].
}
```

Resolution order is:

1. The explicit `CachePolicy` passed to `cached()`
2. The method's `#[Cache]` attribute
3. The class's `#[Cache]` attribute

If none is available, `cached()` throws a `LogicException`.

`CachePolicy` and `#[Cache]` accept the same options:

| Option | Type | Default | Purpose |
|---|---|---|---|
| `ttl` | `int\|Ttl` | `Ttl::Auto` | Selects the boundary expiration |
| `maxTtl` | `?int` | `null` | Caps `Ttl::FromUpstream` |
| `tags` | `list<string>` | `[]` | Adds cache invalidation or response tags |
| `visibility` | `Visibility` | `Visibility::Shared` | Restricts where the result may be stored |
| `clamp` | `bool` | `true` | Prevents a fixed TTL from extending dependency freshness |
| `version` | `string` | `'1'` | Changes the generated cache-key namespace |

## Fixed TTL

An integer TTL is relative to the time at which the origin result is produced:

```php
#[Cache(ttl: 60)]
```

By default, a fixed TTL is clamped to the earliest dependency expiration. If a dependency has 20 seconds left, a 60-second boundary still expires after 20 seconds.

Set `clamp: false` only when the boundary intentionally replaces the dependency expiration:

```php
#[Cache(ttl: 60, clamp: false)]
```

This changes expiration only. Cacheability, visibility, tags, and reasons remain monotone and cannot be relaxed by the policy.

A TTL of `0` is valid, but its expiration is not in the future, so the result is returned without being stored.

## Automatic TTL

`Ttl::Auto` inherits the finite expiration carried by the origin result:

```php
use Magix\Cache\Runtime\Policy\Ttl;

#[Cache(ttl: Ttl::Auto)]
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

`Ttl::Auto` requires the returned `Cached` value to already have a finite expiration. A value created with `Cached::of($value)` has unconstrained metadata until a policy is applied, so using it directly with `Ttl::Auto` throws a `LogicException`.

A `DynamicTtlCacheStrategy` can supply the finite expiration before the automatic policy is applied. See [Cache Strategies](cache-strategies.md#dynamic-ttl).

## Upstream TTL

`Ttl::FromUpstream` inherits an absolute expiration supplied in the returned metadata and caps it with `maxTtl`:

```php
use Magix\Cache\Runtime\Metadata\CacheMetadata;
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

The effective expiration is the earlier of the upstream expiration and `now + maxTtl`. `maxTtl` is required for this mode.

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

The order is `Shared < Private < NoStore`; a parent boundary cannot loosen a dependency's visibility.

Declare a boundary-wide restriction in the policy:

```php
use Magix\Cache\Runtime\Metadata\Visibility;

#[Cache(ttl: 30, visibility: Visibility::Private)]
```

Use `#[CacheScope]` when a parameter introduces the restriction. The parameter remains part of the key by default:

```php
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Runtime\Metadata\Visibility;

#[Cache(ttl: 30)]
public function execute(
    #[CacheScope(Visibility::Private)] int $viewerId,
    int $productId,
): Cached {
    // The result is private, and viewerId differentiates cache entries.
}
```

`#[CacheScope]` defaults to `Visibility::Private`.

An ignored parameter cannot also be scoped unless its scope is `NoStore`. This special combination lets an unkeyable value force execution without storage:

```php
use Closure;
use Magix\Cache\Attribute\CacheIgnore;
use Magix\Cache\Attribute\CacheScope;
use Magix\Cache\Runtime\Metadata\Visibility;

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
use Magix\Cache\Runtime\Metadata\CacheMetadata;

return Cached::of(
    $value,
    CacheMetadata::uncacheable('authorization-dependent'),
);
```

Uncacheable metadata sets `cacheable` to `false`, visibility to `NoStore`, and retains the reason. Once introduced, later composition or policies cannot make the result cacheable again.

## Cache Version

The version is part of the default cache key. Change it when a deployment changes the meaning or serialized shape of an entry and old entries must no longer be read:

```php
#[Cache(ttl: 300, version: 'product-v3')]
```

Versions must be non-empty strings. Changing the version leaves old backend entries in place until their physical expiration; it only moves new reads and writes to a different key.
