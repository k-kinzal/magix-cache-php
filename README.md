# MagixCache

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://www.php.net/)

> [!CAUTION]
> This project has not been published on Packagist yet.

MagixCache is a cacheability propagation library for PHP 8.5+ that safely composes cache constraints across multi-stage queries during server-side rendering.

This monorepo contains a framework-independent core with PSR-6 and PSR-16 adapters, plus dedicated integrations for Laravel and Symfony.

## Packages

| Package | Description |
|---|---|
| [magix-cache](packages/magix-cache/) | Core library: policies, metadata composition, cache runtime, and PSR adapters |
| [magix-cache-laravel](packages/magix-cache-laravel/) | Laravel 12 / 13 integration using the default cache store |
| [magix-cache-symfony](packages/magix-cache-symfony/) | Symfony 7.4 / 8 integration using the `cache.app` pool |

## Quick Start

```bash
composer require k-kinzal/magix-cache
```

```php
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;

final class ProductQuery
{
    use Cacheable;

    #[Cache(ttl: 20)]
    public function execute(int $productId): Cached
    {
        return $this->cached(
            fn (): Cached => Cached::of(Product::find($productId)),
        );
    }
}
```

See [packages/magix-cache/README.md](packages/magix-cache/README.md) for full documentation.

## License

MIT License. See [LICENSE](LICENSE) for details.

