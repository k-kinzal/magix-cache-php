# Getting Started

This guide explains how to install MagixCache, connect a cache backend, and add a cache boundary to a query.

## Requirements

- PHP 8.5 or later
- Composer
- A PSR-6 or PSR-16 cache implementation

Laravel and Symfony applications can use the dedicated integration packages instead of configuring the runtime manually.

## Installation

Install the framework-independent package with Composer:

```bash
composer require k-kinzal/magix-cache
```

## Configure the Runtime

MagixCache needs one process-local `CacheRuntime`. Connect an existing PSR-16 cache with the included adapter:

```php
<?php

use Magix\Cache\Cache\PSR16\SimpleCache as MagixSimpleCache;
use Magix\Cache\CacheRuntime;
use Psr\SimpleCache\CacheInterface;

/** @var CacheInterface $cache */
$cache = $container->get(CacheInterface::class);

CacheRuntime::setCurrent(
    new CacheRuntime(new MagixSimpleCache($cache)),
);
```

For a PSR-6 pool, use `CacheItemPool` instead:

```php
<?php

use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Psr\Cache\CacheItemPoolInterface;

/** @var CacheItemPoolInterface $pool */
$pool = $container->get(CacheItemPoolInterface::class);

CacheRuntime::setCurrent(new CacheRuntime(new CacheItemPool($pool)));
```

Install the runtime during application bootstrap, before any cached query is executed. In tests or application lifecycles that replace the runtime, remove it with `CacheRuntime::setCurrent(null)` during cleanup.

The framework adapters perform this setup for you:

- [Laravel integration](../../magix-cache-laravel/README.md)
- [Symfony integration](../../magix-cache-symfony/README.md)

## Define a Cached Query

Add the `Cacheable` trait, declare a policy with `#[Cache]`, and return `Cached<T>` from the query method:

```php
<?php

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

final class ProductQuery
{
    use Cacheable;

    public function __construct(private ProductRepository $products)
    {
    }

    /** @return Cached<Product> */
    #[Cache(ttl: 20, tags: ['products'])]
    public function execute(int $productId): Cached
    {
        return $this->cached(
            fn (): Cached => Cached::of(
                $this->products->find($productId),
            ),
        );
    }
}
```

The closure must return a `Cached` value. On the first call, MagixCache runs the closure, applies the 20-second policy, and stores the value together with its cache metadata. A second call with the same argument returns the stored result without running the closure.

The cache key includes the declaring class, method, arguments, and policy version. See [Cache Keys](cache-keys.md) for key reduction, ignored arguments, and custom key strategies.

## Read the Result

Use `value()` whenever PHP needs the original type explicitly:

```php
/** @var Product $product */
$product = $query->execute(42)->value();
```

`Cached` also forwards public property reads, public method calls, `isset()`, and string conversion where the wrapped value supports them:

```php
$result = $query->execute(42);

echo $result->name;
echo $result->displayName();
```

The cache constraints are available through the immutable `metadata` property:

```php
$result->metadata->expiresAt;
$result->metadata->cacheable;
$result->metadata->visibility;
$result->metadata->tags;
$result->metadata->reasons;
```

## Compose Queries

When a query depends on other cached queries, combine their values instead of discarding their metadata:

```php
<?php

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Runtime\Policy\Ttl;

final class ProductPageQuery
{
    use Cacheable;

    public function __construct(
        private ProductQuery $products,
        private InventoryQuery $inventory,
    ) {
    }

    /** @return Cached<ProductPage> */
    #[Cache(ttl: Ttl::Auto, tags: ['product-pages'])]
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
}
```

If the product expires in 20 seconds and inventory expires in 60 seconds, the composed page expires in 20 seconds. Cacheability, visibility, tags, and diagnostic reasons are also combined conservatively. See [Cache Composition](cache-composition.md) for all composition rules.

## Use an Explicit Policy

Attributes are optional. Pass a `CachePolicy` as the second argument to `cached()` when a policy must be selected in code:

```php
use Magix\Cache\CachePolicy;

return $this->cached(
    fn (): Cached => Cached::of($this->products->find($productId)),
    new CachePolicy(ttl: 20, tags: ['products']),
);
```

An explicit policy takes precedence over `#[Cache]`. Every call to `cached()` requires either an explicit policy or a cache attribute on the method or class.

## Next Steps

- [Cache Policies](cache-policies.md): TTL modes, visibility, tags, versions, and parameter scopes
- [Cache Keys](cache-keys.md): Default key behavior and argument attributes
- [Cache Composition](cache-composition.md): Combine nested query values without losing constraints
- [Storage Adapters](storage-adapters.md): PSR-6, PSR-16, and custom cache implementations
- [Cache Strategies](cache-strategies.md): Dynamic TTL, stale-if-error, backend failures, and custom middleware
