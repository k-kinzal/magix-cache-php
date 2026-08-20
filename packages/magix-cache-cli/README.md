# MagixCache CLI

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

`magix` makes the caches of a MagixCache project visible. It reads the source of a project without running it, resolves every `cached()` call site, and composes the constraints of the boundaries that call each other, exactly the way `CacheRuntime` does at runtime.

The result answers the questions that are otherwise only observable in production: what is actually cached, for how long, under which key, and who is allowed to read the entry.

## Features

- Cache trees for one boundary, with the effective TTL, visibility, tags, and key of every node
- The reason behind each effective value, such as which dependency clamped a TTL or made a result private
- An inventory of every boundary in a project as a table or as JSON
- Static rules that report boundaries that throw, never store, or share a private entry between viewers
- The exact cache key of a call, so an entry can be found in the backend
- Tree, table, JSON, and Mermaid output for terminals, editors, and documentation

## Requirements

- PHP 8.5 or later
- Composer
- A project that uses [k-kinzal/magix-cache](../magix-cache/)

## Installation

```bash
composer require --dev k-kinzal/magix-cache-cli
```

## Quick Start

```bash
vendor/bin/magix analyze ProductPageQuery::execute
```

```text
App\Query\ProductPageQuery::execute
  src/Query/ProductPageQuery.php:34

  ttl          20s (declared 120s, clamped by ProductQuery::execute)
  visibility   private (restricted by ViewerQuery::execute)
  storable     yes
  tags         inventory, page, product, viewer
  key          $productId, $viewerId (ignored: $trace)  version 1
  policy       #[Cache(ttl: 120s, tags: [page])]

ProductPageQuery::execute  ttl 20s (declared 120s)  private  tags inventory,page,product,viewer
|-- ProductQuery::execute  ttl 20s  shared  tags product
|-- InventoryQuery::execute  ttl 60s  shared  tags inventory
`-- ViewerQuery::execute  ttl 30s  private  tags viewer
```

The boundary declares 120 seconds, but `ProductQuery` expires after 20, and `ViewerQuery` is personalized, so the page is stored privately for 20 seconds. Nothing needs to be executed to see this.

## Commands

| Command | Purpose |
|---|---|
| `magix analyze <boundary>` | Expands one boundary into its cache tree with keys, TTLs, scopes, and tags |
| `magix boundaries` | Lists every boundary of the project with its effective values |
| `magix lint` | Reports boundaries that cannot behave the way they are declared |
| `magix key <boundary> [arguments]` | Prints the cache key one call produces |

Every command scans the Composer autoload roots of the current directory by default. Use `--path` once per directory or file to scan something else:

```bash
vendor/bin/magix lint --path=src/Query --path=modules
```

See [Commands](docs/commands.md) for every option and [Lint Rules](docs/lint-rules.md) for what `magix lint` reports.

## Continuous Integration

`magix lint` exits with a failure when it finds an error, which makes it usable as a build step. Add `--strict` to fail on warnings as well:

```yaml
- name: Check cache boundaries
  run: vendor/bin/magix lint --strict
```

## How It Works

The commands never execute application code. Each PHP file below the scanned paths is parsed, and every method that calls `$this->cached()` becomes a boundary. Attributes, explicit `CachePolicy` arguments, and parameter attributes are read from the syntax tree, and calls to other boundaries are resolved through the declared types of properties, local variables, and interfaces.

The composition rules are the ones the runtime applies: the earliest expiration wins, cacheability is combined with logical AND, the strictest visibility wins, and tags are unioned. A fixed TTL is clamped by its dependencies unless the policy sets `clamp: false`.

Because the analysis is static, values that only exist at runtime are reported instead of guessed. A policy built from a variable is shown as `unresolved`, an expiration supplied by a `CacheStrategy` is shown as `supplied at runtime`, and a call that resolves to several implementations expands into all of them.

`magix key` is the one exception: it loads the referenced class through the Composer autoloader so that `#[CacheKey]` reducers produce the same key as the runtime.

## Documentation

- [Commands](docs/commands.md): Every command, option, and output format
- [Lint Rules](docs/lint-rules.md): What each rule reports and how to resolve it
- [MagixCache](../magix-cache/README.md): The library these commands analyze
- [Package Overview](../../README.md): View every package in this monorepo

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [nikic/php-parser](https://github.com/nikic/PHP-Parser) for the syntax trees the analysis is built on
- [Symfony Console](https://symfony.com/doc/current/components/console.html) for the command line interface
