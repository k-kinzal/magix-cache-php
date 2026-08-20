# MagixCache

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

MagixCache is a PSR-compatible cacheability propagation library for PHP 8.5. It carries values and their cache constraints together so that nested query results can be cached safely during server-side rendering.

Storage remains independent from the core behavior. Connect any PSR-6 or PSR-16 implementation through the included adapters.

## Features

- Immutable values and cache metadata through `Cached<T>`
- Safe composition using the earliest expiration, logical AND for cacheability, the strictest visibility, and the union of tags and diagnostic reasons
- Automatic cache keys derived from the class, method, arguments, and policy version
- Explicit `CachePolicy` objects and optional PHP attributes
- Transparent property, method, and string access to wrapped values
- Independent PSR-6 and PSR-16 adapters
- Per-boundary strategies for dynamic TTLs, stale-if-error, and cache-backend errors

## Requirements

- PHP 8.5 or later
- Composer
- A PSR-6 or PSR-16 cache implementation

## Installation

```bash
composer require k-kinzal/magix-cache
```

## Quick Start

```php
<?php

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cache\PSR16\SimpleCache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\CacheRuntime;
use Psr\SimpleCache\CacheInterface;

/** @var CacheInterface $cache */
CacheRuntime::setCurrent(new CacheRuntime(new SimpleCache($cache)));

final class ProductQuery
{
    use Cacheable;

    public function __construct(private ProductRepository $products)
    {
    }

    #[Cache(ttl: 20, tags: ['products'])]
    public function execute(int $productId): Cached
    {
        return $this->cached(
            fn (): Cached => Cached::of($this->products->find($productId)),
        );
    }
}
```

Every method argument is included in the cache key by default. On a hit, the stored value and metadata are returned as `Cached` without running the compute closure.

Use `combine2()` through `combine5()` to compose nested values. Their metadata can only become stricter: expiration moves earlier, cacheability uses logical AND, visibility becomes more restrictive, and tags and diagnostic reasons are combined.

## Documentation

For more detailed information, check out the documentation:

- [Getting Started](docs/getting-started.md): Installation, runtime setup, and a first cached query
- [Cache Policies](docs/cache-policies.md): TTL modes, tags, visibility, versions, and scopes
- [Cache Keys](docs/cache-keys.md): Default keys, argument reduction, ignored arguments, and custom strategies
- [Cache Composition](docs/cache-composition.md): Safely combine cached values and their constraints
- [Storage Adapters](docs/storage-adapters.md): PSR-6, PSR-16, framework integrations, and custom storage
- [Cache Strategies](docs/cache-strategies.md): Dynamic TTL, stale-if-error, backend failures, and custom middleware
- [Command Line Tools](../magix-cache-cli/README.md): Show cache trees, keys, and scopes, and lint boundaries
- [Laravel Integration](../magix-cache-laravel/README.md): Connect MagixCache to Laravel's default cache store
- [Symfony Integration](../magix-cache-symfony/README.md): Connect MagixCache to Symfony's `cache.app` pool
- [Package Overview](../../README.md): View every package in this monorepo

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [PHP-FIG](https://www.php-fig.org/) for the PSR cache and clock standards
- All contributors who have helped improve MagixCache
