# Getting Started

This guide explains how to install MagixCache, connect a cache backend, and add a cache boundary to a query.

## Requirements

- PHP 8.3 or later
- Composer
- A PSR-6 or PSR-16 cache implementation

Laravel and Symfony applications can use the dedicated integration packages instead of configuring the runtime manually.

## Installation

Install the framework-independent package with Composer:

```bash
composer require k-kinzal/magix-cache
```

## Register a Runtime

Cache boundaries reference runtimes by name. Register a `CacheRuntime` under the `default` name at bootstrap, before any cached query executes. Connect an existing PSR-16 cache with the included adapter:

```php
<?php

use Magix\Cache\Cache\PSR16\SimpleCache as MagixSimpleCache;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Psr\SimpleCache\CacheInterface;

/** @var CacheInterface $cache */
$cache = $container->get(CacheInterface::class);

CacheRuntimeRegistry::register(
    'default',
    new CacheRuntime(new MagixSimpleCache($cache)),
);
```

For a PSR-6 pool, use `CacheItemPool` instead:

```php
<?php

use Magix\Cache\Cache\PSR6\CacheItemPool;
use Magix\Cache\CacheRuntime;
use Magix\Cache\Runtime\CacheRuntimeRegistry;
use Psr\Cache\CacheItemPoolInterface;

/** @var CacheItemPoolInterface $pool */
$pool = $container->get(CacheItemPoolInterface::class);

CacheRuntimeRegistry::register('default', new CacheRuntime(new CacheItemPool($pool)));
```

A registered name is fixed once and never rebound; a boundary that references an unregistered name is a definition error, not a fallback. For request-scoped integrations, register a provider closure instead of an instance — it is invoked on every resolution and its result is never memoized by the registry:

```php
CacheRuntimeRegistry::register(
    'default',
    static fn (): CacheRuntime => $container->get(CacheRuntime::class),
);
```

In tests, clear all registrations with `CacheRuntimeRegistry::reset()` during cleanup.

The framework adapters register the `default` runtime for you:

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

`cached()` takes exactly one closure, and the closure must return a `Cached` value — wrap even a plain leaf value explicitly with `Cached::of()`. On the first call, MagixCache runs the closure, applies the 20-second policy, and stores the value together with its cache metadata. A second call with the same argument returns the stored result without running the closure.

Policy and behaviors come from attributes alone: `#[Cache]` on the method wins over the concrete class as a whole, and there is no per-call override argument. A method without any `#[Cache]` on itself or its concrete class throws a `LogicException`. `cached()` must also be called directly from the boundary method, not through a helper or closure.

The cache key includes the runtime namespace, the concrete class, the declaring method, the arguments, the policy version, and a fingerprint of the effective declaration. See [Cache Keys](cache-keys.md) for key reduction, ignored arguments, and custom key strategies.

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

final class ProductPageQuery
{
    use Cacheable;

    public function __construct(
        private ProductQuery $products,
        private InventoryQuery $inventory,
    ) {
    }

    /** @return Cached<ProductPage> */
    #[Cache(tags: ['product-pages'])]
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

The TTL can be omitted: `#[Cache]` alone inherits the composed constraints, and this example adds only a page tag. If the product expires in 20 seconds and inventory expires in 60 seconds, the composed page expires in 20 seconds. Cacheability, visibility, tags, and diagnostic reasons are also combined conservatively — a declared TTL can shorten the result's lifetime but never extend what a dependency imposed. See [Cache Composition](cache-composition.md) for all composition rules, including the `value()` pitfall.

## Select a Runtime per Boundary

Applications with more than one cache backend register additional runtimes under their own names and reference them from the declaration:

```php
CacheRuntimeRegistry::register('reporting', new CacheRuntime(new CacheItemPool($reportingPool)));
```

```php
#[Cache(ttl: 300, runtime: 'reporting')]
public function execute(): Cached
{
    return $this->cached(fn (): Cached => Cached::of($this->report->build()));
}
```

## Next Steps

- [Cache Policies](cache-policies.md): TTL modes, visibility, tags, versions, and parameter scopes
- [Cache Keys](cache-keys.md): Default key behavior and argument attributes
- [Cache Composition](cache-composition.md): Combine nested query values without losing constraints
- [Storage Adapters](storage-adapters.md): PSR-6, PSR-16, and custom cache implementations
- [Cache Behaviors](cache-behaviors.md): Stale-if-error, dynamic TTL, backend-failure bypass, and observation
