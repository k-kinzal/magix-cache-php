# MagixCache Laravel Integration

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

The Laravel integration connects MagixCache to Laravel 12 and 13. It registers a `CacheRuntime` backed by Laravel's default cache store as the `default` runtime reference, allowing cached queries to work without manual runtime configuration.

Laravel continues to own the storage backend and its configuration.

## Features

- Supports Laravel 12 and 13
- Registers automatically through Laravel Package Discovery
- Uses Laravel's default `cache.store`
- Adapts the store through the MagixCache PSR-16 adapter
- Registers `CacheRuntime` as a container singleton
- Fixes the `default` reference in `CacheRuntimeRegistry` with a container-backed provider
- Supports every cache backend exposed by Laravel's cache configuration

## Requirements

- PHP 8.5 or later
- Laravel 12 or 13
- A configured Laravel cache store

## Installation

```bash
composer require k-kinzal/magix-cache-laravel
```

## Quick Start

Package Discovery registers the runtime automatically. Add `Cacheable` to a query and return a `Cached` value.

```php
<?php

use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

final class ProductQuery
{
    use Cacheable;

    #[Cache(ttl: 20, tags: ['products'])]
    public function execute(int $productId): Cached
    {
        return $this->cached(
            fn (): Cached => Cached::of(Product::query()->findOrFail($productId)),
        );
    }
}
```

The query uses the application's configured `cache.store`, including Redis, Memcached, database, and other Laravel cache drivers.

## How Registration Works

At boot the service provider fixes the `default` runtime reference in `CacheRuntimeRegistry` to a provider closure that resolves `CacheRuntime` through the container on every lookup, so the registry never holds a runtime instance across requests. Runtime names are registered once per process and never rebound; if the application has already registered `default` during bootstrap — for example to add TTL resolvers, error classifiers, or an observer — the provider leaves that registration in place.

## Documentation

For more detailed information, check out the documentation:

- [MagixCache Core](../magix-cache/README.md): Policies, cache keys, composition, and PSR adapters
- [Cache Behaviors](../magix-cache/docs/cache-behaviors.md): Stale-if-error, dynamic TTL, and backend-failure bypass
- [Package Overview](../../README.md): View every package in this monorepo

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [Laravel](https://laravel.com/) for the framework and cache abstraction
- All contributors who have helped improve MagixCache
