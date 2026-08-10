# Cache Composition

This guide explains how `Cached<T>` propagates cache constraints through multi-stage queries.

## Why Composition Matters

A query result is only as cacheable as the data used to build it. If a page combines a product that expires in 20 seconds with inventory that expires in 60 seconds, caching the page for 60 seconds would allow stale product data to survive too long.

MagixCache keeps the value and its constraints together in `Cached<T>`. Composition merges those constraints conservatively, so a parent result cannot become less restricted than a dependency.

## Combine Two Values

Use `combine2()` followed by `map()`:

```php
/** @var Cached<Product> $product */
$product = $productQuery->execute($productId);

/** @var Cached<Inventory> $inventory */
$inventory = $inventoryQuery->execute($productId);

/** @var Cached<ProductPage> $page */
$page = $product
    ->combine2($inventory)
    ->map(
        static fn (Product $product, Inventory $inventory): ProductPage =>
            new ProductPage($product, $inventory),
    );
```

The mapping closure receives the unwrapped values. Its return value is wrapped in a new `Cached` carrying the merged metadata.

## Combine Three to Five Values

The same API is available for three, four, or five dependencies:

```php
$viewModel = $product
    ->combine3($inventory, $pricing)
    ->map(
        static fn (
            Product $product,
            Inventory $inventory,
            Pricing $pricing,
        ): ProductViewModel => new ProductViewModel(
            $product,
            $inventory,
            $pricing,
        ),
    );
```

Use `combine4()` and `combine5()` for four and five values respectively. Each method returns a typed capability whose `map()` closure receives the values in the same order.

For more than five inputs, compose intermediate domain values and combine those results in another step. The constraints remain monotone at each step.

## Merge Rules

Composition applies these rules:

| Constraint | Merge rule |
|---|---|
| Expiration | Earliest finite absolute expiration; `null` is unconstrained |
| Cacheability | Logical AND |
| Visibility | Most restrictive: `Shared < Private < NoStore` |
| Tags | Deduplicated, sorted union |
| Reasons | Deduplicated, sorted union |

For example:

```text
Product:   expires in 20s, Shared, tags [products]
Inventory: expires in 60s, Private, tags [inventory]
Result:    expires in 20s, Private, tags [inventory, products]
```

An uncacheable or `NoStore` dependency makes the composed result uncacheable or `NoStore`. A later policy cannot loosen those constraints.

## Apply the Parent Policy

Composition happens inside the origin closure. The enclosing cache boundary applies its policy afterward:

```php
use Magix\Cache\Runtime\Policy\Ttl;

#[Cache(ttl: Ttl::Auto, tags: ['product-pages'])]
public function execute(int $productId): Cached
{
    return $this->cached(function () use ($productId): Cached {
        return $this->products->execute($productId)
            ->combine2($this->inventory->execute($productId))
            ->map(
                static fn (Product $product, Inventory $inventory): ProductPage =>
                    new ProductPage($product, $inventory),
            );
    });
}
```

`Ttl::Auto` retains the composed expiration. A fixed parent TTL with the default `clamp: true` chooses the earlier of its own expiration and the composed expiration.

## Create Source Metadata

Most dependencies receive metadata when their own cache boundary applies a policy. A source can also attach constraints directly:

```php
use Magix\Cache\Runtime\Metadata\CacheMetadata;

$result = Cached::of(
    $response->value,
    new CacheMetadata(
        expiresAt: $response->expiresAt,
        tags: ['upstream-products'],
    ),
);
```

For a TTL relative to a known timestamp, use `CacheMetadata::forTtl()`:

```php
$result = Cached::of(
    $value,
    CacheMetadata::forTtl(ttl: 30, now: $now, tags: ['products']),
);
```

To forbid storage and preserve a diagnostic reason:

```php
$result = Cached::of(
    $value,
    CacheMetadata::uncacheable('authorization-dependent'),
);
```

`CacheMetadata` is immutable. Its `merge()`, `withExpiresAt()`, `withTags()`, and `withVisibility()` methods return new instances.

## Access Wrapped Values

Use `value()` for scalar operations, strict parameter types, or explicit unwrapping:

```php
$product = $cachedProduct->value();
```

For convenience, `Cached` forwards:

- Public object property reads
- String-keyed array reads
- `isset()` checks
- Callable public object methods
- String conversion for strings and `Stringable` objects

These forwarded operations return ordinary PHP values. Use `combineN()->map()` when producing a new cached result, so the dependency metadata is not lost.

## Use Metadata at the Response Boundary

After composing the final page or read model, application code can inspect its metadata to select HTTP cache headers or invalidation tags:

```php
$page = $pageQuery->execute($productId);
$metadata = $page->metadata;

if (!$metadata->cacheable) {
    // Emit no-store behavior and optionally log $metadata->reasons.
}
```

The core library propagates the constraints but intentionally does not depend on an HTTP framework. Header generation remains in the application or a framework-specific adapter.
