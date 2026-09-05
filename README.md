# MagixCache

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://www.php.net/)
[![docs](https://img.shields.io/badge/docs-magix--cache-0969da?logo=php&logoColor=white)](https://k-kinzal.github.io/magix-cache-php/)

> [!CAUTION]
> This project has not been published on Packagist yet.

MagixCache is a cacheability propagation library for PHP 8.5+ that safely composes cache constraints across multi-stage queries during server-side rendering.

This monorepo contains a framework-independent core with PSR-6 and PSR-16 adapters, dedicated integrations for Laravel and Symfony, and command line tools that make the resulting caches visible.

## Packages

| Package | Description |
|---|---|
| [magix-cache](packages/magix-cache/) | Core library: policies, metadata composition, cache runtime, and PSR adapters |
| [magix-cache-laravel](packages/magix-cache-laravel/) | Laravel 12 / 13 integration using the default cache store |
| [magix-cache-symfony](packages/magix-cache-symfony/) | Symfony 7.4 / 8 integration using the `cache.app` pool |
| [magix-cache-cli](packages/magix-cache-cli/) | `magix` commands that show cache trees, keys, TTLs, and scopes |

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

Register a `CacheRuntime` under the `default` name in `CacheRuntimeRegistry` at bootstrap; the Laravel and Symfony packages do this automatically.

See [packages/magix-cache/README.md](packages/magix-cache/README.md) for full documentation.

## Seeing What Is Cached

Composed caches are hard to reason about from the source alone. The `magix` commands read a project without running it and show what each boundary really does:

```bash
composer require --dev k-kinzal/magix-cache-cli

vendor/bin/magix analyze ProductPageQuery::execute
```

The output looks along these lines:

```text
App\Query\ProductPageQuery::execute
  src/Query/ProductPageQuery.php:34

  ttl          20s (declared 120s, capped by ProductQuery::execute)
  visibility   private (restricted by ViewerQuery::execute)
  storable     yes
  tags         inventory, page, product, viewer
  key          $productId, $viewerId (ignored: $trace)  version 1
  policy       #[Cache(ttl: 120, tags: ['page'])]

ProductPageQuery::execute  ttl 20s (declared 120s)  private  tags inventory,page,product,viewer
|-- ProductQuery::execute  ttl 20s  shared  tags product
|-- InventoryQuery::execute  ttl 60s  shared  tags inventory
`-- ViewerQuery::execute  ttl ≤30s, requires finite upstream  private  tags viewer
```

Because the analysis is static, a TTL is not always a single number: it may be reported as a known value, as unconstrained, or as a conditional upper bound such as "≤30s, requires a finite upstream expiration at runtime".

`magix boundaries` lists every boundary of a project, `magix lint` fails a build when a declaration cannot hold at runtime — for example a derived TTL with no upstream expiration, or a private entry shared between viewers — and `magix key` prints the key of one call so an entry can be found in the backend. See [packages/magix-cache-cli/README.md](packages/magix-cache-cli/README.md).

## License

MIT License. See [LICENSE](LICENSE) for details.
