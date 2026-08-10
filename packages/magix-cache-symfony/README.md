# MagixCache Symfony Integration

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

The Symfony integration connects MagixCache to Symfony 7.4 and 8. It installs a `CacheRuntime` backed by the `cache.app` pool, allowing cached queries to use the application's existing cache configuration.

Symfony continues to own the storage backend and its configuration.

## Features

- Supports Symfony 7.4 and 8
- Registers MagixCache through a Symfony bundle
- Uses the application's `cache.app` pool
- Adapts the pool through the MagixCache PSR-6 adapter
- Registers `CacheRuntime` as a public container service
- Installs and removes the process-local runtime with the kernel lifecycle

## Requirements

- PHP 8.5 or later
- Symfony FrameworkBundle 7.4 or 8
- A configured `cache.app` pool

## Installation

```bash
composer require k-kinzal/magix-cache-symfony
```

## Quick Start

Enable `MagixCacheBundle` in the application:

```php
<?php

// config/bundles.php

return [
    Magix\Cache\Symfony\MagixCacheBundle::class => ['all' => true],
];
```

The bundle registers `CacheRuntime` automatically and connects it to `cache.app`. Cached queries can then use the core `Cacheable` API without manual runtime configuration.

## Documentation

For more detailed information, check out the documentation:

- [MagixCache Core](../magix-cache/README.md): Policies, cache keys, composition, and PSR adapters
- [Cache Strategies](../magix-cache/docs/cache-strategies.md): Per-boundary cache behavior and custom strategies
- [Package Overview](../../README.md): View every package in this monorepo

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [Symfony](https://symfony.com/) for the framework and cache components
- All contributors who have helped improve MagixCache
