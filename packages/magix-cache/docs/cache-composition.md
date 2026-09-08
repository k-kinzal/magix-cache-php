# Cache Composition

This guide explains how `Cached<T>` propagates cache constraints through multi-stage queries.

## Why Composition Matters

A query result is only as cacheable as the data used to build it. If a page combines a product that expires in 20 seconds with inventory that expires in 60 seconds, caching the page for 60 seconds would allow stale product data to survive too long.

MagixCache keeps the value and its constraints together in `Cached<T>`. Composition merges those constraints with a fixed meet law, so a parent result cannot become less restricted than a dependency.

## Transform One Value

`map()` transforms the value in place and keeps this value's metadata:

```php
/** @var Cached<Product> $product */
$product = $productQuery->execute($productId);

/** @var Cached<string> $name */
$name = $product->map(static fn (Product $product): string => $product->name);
```

The transform result is treated as a plain value: a `Cached` returned from it is not flattened. Use `flatMap()` when the transform obtains a new dependency:

```php
/** @var Cached<ProductPage> $page */
$page = $productQuery->execute($productId)->flatMap(
    fn (Product $product): Cached => $this->inventory
        ->execute($product->id)
        ->map(
            static fn (Inventory $inventory): ProductPage =>
                new ProductPage($product, $inventory),
        ),
);
```

`flatMap()` meets the metadata of both values, so the inventory constraints survive into the page.

> [!WARNING]
> `value()` detaches a value from its constraints. Calling another cached query inside `map()` and using only its `value()` silently drops that query's constraints:
>
> ```php
> // WRONG: the inventory expiration, visibility, and tags are lost.
> $page = $productQuery->execute($productId)->map(
>     fn (Product $product): ProductPage => new ProductPage(
>         $product,
>         $this->inventory->execute($product->id)->value(),
>     ),
> );
> ```
>
> A dependency obtained inside `map()` must be chained with `flatMap()` or `combineN()` instead.

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

The mapping closure receives the unwrapped values. Its return value is wrapped in a new `Cached` carrying the met metadata of every input.

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

For more than five inputs of different types, compose intermediate domain values and combine those results in another step. For a variable number of results, use `sequence()` or `traverse()` below.

## Flatten a Nested Cached Value

`flatten()` changes `Cached<Cached<T>>` into `Cached<T>`, meeting the outer and inner metadata:

```php
$nested = $productQuery->execute($productId)->map(
    fn (Product $product): Cached => $inventoryQuery->execute($product->id),
);
$inventory = $nested->flatten(); // Cached<Inventory>
```

This is equivalent to using `flatMap()` for that transform. Only one layer is removed; `Cached<Cached<Cached<T>>>` becomes `Cached<Cached<T>>`. An expired inner value stays expired, and an uncacheable inner value stays uncacheable. Calling `flatten()` on a value that is not another `Cached` is a programming error (`LogicException`).

## Pair and Split Typed Values

`zip()` changes `Cached<A>` and `Cached<B>` into `Cached<array{A, B}>`. It is useful when the next consumer accepts a tuple instead of separate callback arguments:

```php
$pair = $product->zip($inventory); // Cached<array{Product, Inventory}>

[$productPart, $inventoryPart] = $pair->unzip();
// Cached<Product>, Cached<Inventory>
```

`unzip()` requires a two-element list and returns two `Cached` values. **Both keep the entire pair's metadata**, including the constraints contributed by the other part. Splitting a private or expired pair cannot make either projection shared or fresh. It does not reconstruct the weaker metadata from before `zip()`. A value that is not a two-element list is a programming error (`LogicException`).

## Collect a Variable Number of Results

`sequence()` changes `iterable<K, Cached<T>>` into `Cached<array<K, T>>`:

```php
$products = Cached::sequence([
    'featured' => $productQuery->execute($featuredId),
    'related' => $productQuery->execute($relatedId),
]);
// Cached<array<string, Product>>
```

`traverse()` maps each ordinary input to a `Cached` result and collects it in the same way:

```php
$products = Cached::traverse(
    $productIds,
    fn (int $id): Cached => $productQuery->execute($id),
);
```

When the list of IDs itself carries constraints, use `flatMap()` to retain them:

```php
$products = $cachedProductIds->flatMap(
    fn (array $ids): Cached => Cached::traverse(
        $ids,
        fn (int $id): Cached => $productQuery->execute($id),
    ),
);
```

Both operations consume arrays or generators eagerly, once, in iteration order. `traverse()` invokes its callback once per input value. Integer and string keys are preserved using PHP array key rules. If a generator repeats a key, the last value wins, but **every yielded result's constraints still contribute**. All items are evaluated even when an earlier item prohibits storage; callback or iteration failures propagate.

Empty input returns `Cached::of([])` with `CacheMetadata::top()` and does not call the transform. It supplies no finite expiration by itself: use a fixed parent TTL or another finite dependency when an empty collection is possible. An automatic TTL requires that finite constraint.

These are eager operations on evaluated values. `null` is an ordinary wrapped value; it does not represent a missing branch, and these methods do not introduce Option/Result types.

## The Meet Law

`CacheMetadata::meet()` is the only way to add constraints to existing metadata, and it is a fixed law:

| Constraint | Meet rule |
|---|---|
| Expiration | Earliest finite absolute expiration; `null` is unconstrained |
| Cacheability | Logical AND |
| Visibility | Stricter of the two: `Shared < Private < NoStore` |
| Tags | Deduplicated, sorted union |
| Reasons | Deduplicated, sorted union |

`CacheMetadata::top()` — no declared constraints — is the identity element: meeting with it changes nothing. Every composition is as strict as or stricter than each input.

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

`Ttl::Auto` retains the composed expiration. A fixed parent TTL is met with the composed metadata, so the earlier of its own expiration and the composed expiration always wins — a parent can never extend what a dependency imposed.

## Create Source Metadata

Most dependencies receive metadata when their own cache boundary applies a policy. A source can also attach constraints directly:

```php
use Magix\Cache\Metadata\CacheMetadata;

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

`CacheMetadata` is immutable and composes only through `meet()`; there are no setters that could relax a constraint.

## Access Wrapped Values

`Cached` exposes its own methods and `metadata`; it does not proxy properties, methods, `isset()`, or string conversion to the wrapped value. Use `value()` at the final observation boundary:

```php
$product = $cachedProduct->value();
echo $product->name;
echo $product->displayName();
echo $cachedTitle->value();

foreach ($cachedProducts->value() as $product) {
    echo $product->name;
}
```

`value()` detaches the value from its constraints. To produce another cached result with a PHP array function, apply it inside `map()` or `combineN()->map()`:

```php
$visibleIds = $allIds->combine2($excludedIds)->map(
    static fn (array $all, array $excluded): array => array_diff($all, $excluded),
);
```

The result keeps the constraints from both queries. It can then be iterated with `foreach ($visibleIds->value() as $id)` when rendering the response.

### Migrating from Magic Access

| Previous access | Explicit access |
|---|---|
| `$cached->name` | `$cached->value()->name` for an object, or `$cached->value()['name']` for an array |
| `$cached->displayName()` | `$cached->value()->displayName()` |
| `isset($cached->name)` | `isset($cached->value()->name)` or `isset($cached->value()['name'])` |
| `(string) $cached` | `(string) $cached->value()` when the value supports string conversion |

Use `map()` for these transformations when the result must remain cached, and `flatMap()` or `flatten()` when the transformation returns another `Cached`.

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
