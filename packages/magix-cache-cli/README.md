# MagixCache CLI

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

`magix` makes the caches of a MagixCache project visible. It reads the source of a project without running it, resolves every `cached()` call site, and composes the constraints of the boundaries that call each other, exactly the way `CacheRuntime` does at runtime.

The result answers the questions that are otherwise only observable in production: what is actually cached, for how long, under which key, and who is allowed to read the entry.

## Features

- Cache trees for one boundary, with the effective TTL, visibility, tags, and key of every node
- Optional ordinary method calls with `--show-uncached`, and independent subtree filters with repeatable `--ignore` patterns
- Explicit analysis gaps when a cache boundary reaches another cache through ordinary methods, with unverified metadata kept unknown
- The reason behind each effective value, such as which dependency capped a TTL or made a result private
- White rows for provably storable caches and gray rows for other nodes, so the extent of cache bubbling is visible at a glance
- Yellow fields where local TTL, `maxTtl`, or visibility settings restrict a storable result
- Composed strategy contracts, bound to the same `create()` the runtime calls, with candidate ranges such as `30-60s` kept apart from the effective TTL
- The default hash strategy's cache key for a call in the configured runtime namespace
- Tree, JSON, and Mermaid output for terminals, editors, and documentation

## Requirements

- PHP 8.3 or later
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

  ttl          20s (declared 120s, capped by ProductQuery::execute)
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

In a color terminal, these storable boundaries appear white. A `NoStore` result and the parents it constrains appear gray, while stored children remain white. Uncached methods, missing `#[Cache]` declarations, zero TTLs, and results whose storage depends on runtime values are also gray. `shared` and `private` retain their text labels without separate colors. Use `--ansi` to force colors or `--no-ansi` for plain text.

For a parent that only bubbles up child constraints, declare `#[Cache]` without a TTL. The tree shows the effective values directly, without a `(declared Ttl::Auto)` annotation, and the policy row uses `#[Cache]` (or includes any additional options). Explicit `ttl: Ttl::Auto` renders the same way. Fixed TTL declarations, upstream caps, and unknown or invalid lifetime diagnostics remain visible; JSON retains the normalized TTL mode in `policy.ttl`.

## Commands

| Command | Purpose |
|---|---|
| `magix analyze <boundary>` | Expands a cache boundary or uncached controller action into its composed cache tree |
| `magix key <boundary> [arguments]` | Prints the cache key one call produces |

Every command scans the Composer autoload roots of the current directory by default. Use `--path` once per directory or file to scan something else:

```bash
vendor/bin/magix analyze ProductPageQuery::execute --path=src/Query --path=modules
```

See [Commands](docs/commands.md) for every option. `magix list` lists the available commands.

## How It Works

`magix analyze` never executes application code. Each PHP file below the scanned paths is parsed, and every method that calls `$this->cached()` becomes a boundary. The `#[Cache]` declaration, behavior attributes such as `#[DynamicTtl]`, and parameter attributes are read from the syntax tree, and calls to other boundaries are resolved through the declared types of properties, local variables, and interfaces.

The composition rules are the ones the runtime applies: the earliest expiration wins, cacheability is combined with logical AND, the strictest visibility wins, and tags are unioned. A fixed TTL is always bounded by the expiration its dependencies impose.

Because the analysis is static, the effective TTL is an honest estimate rather than a guess. A lifetime is reported as a number only when it is statically determined; a boundary that is provably without expiration is `unconstrained`; anything that depends on runtime values, such as a `#[DynamicTtl]` resolver or an upstream the analyzer cannot see, is `unknown`, together with the tightest provable upper bound such as `unknown (≤30s)`; and a declaration that throws at runtime is `invalid`. A call that resolves to several implementations expands into all of them.

The call graph follows dependencies inside composition callbacks, including `traverse()`, and preserves both dependencies when `unzip()` selects one side of a pair. It does not prove which callbacks execute or whether an iterable is non-empty. Check the empty collection path separately: `sequence()` and `traverse()` return unconstrained metadata for empty input, so an automatic parent TTL still needs a finite constraint from elsewhere on that path.

A cache parent calling a cache child through ordinary methods is reported as
`cache propagation unanalyzed`, even without `--show-uncached`. Those methods
might return `Cached` intact or detach its metadata with `value()`; the call graph
does not prove either. The report displays the intervening path and keeps the
parent's TTL, visibility, and tags uncertain while preserving proven constraints.
JSON exposes these paths in `analysisGaps`. See [analysis gaps](docs/commands.md#cache-propagation-gaps)
for the distinction from ordinary uncached calls and invalid declarations.

`magix key` is the one exception: it loads the referenced class through the Composer autoloader and runs its `#[CacheKey]` reducers and strategy factories. It computes the default hash strategy's key with the runtime's default namespace, `magix`; supply `--namespace` when the application configures another namespace. The boundary body is not called and no cache entries are read or written.

## Documentation

- [Commands](docs/commands.md): Every command, option, and output format
- [MagixCache](../magix-cache/README.md): The library these commands analyze
- [Package Overview](../../README.md): View every package in this monorepo

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [nikic/php-parser](https://github.com/nikic/PHP-Parser) for the syntax trees the analysis is built on
- [Symfony Console](https://symfony.com/doc/current/components/console.html) for the command line interface

## Parameter Configuration

`magix analyze` understands `CacheTtl`, `CacheTags`,
`CacheVisibility`, and `StrategyArgument` on boundary parameters. Configuration
values remain runtime-dependent even when defaults are declared. TTL caps are
preserved, strategy labels show their source parameters, and dynamic visibility
and tags remain explicit throughout the dependency tree. JSON includes
`visibilityUnknown` and `tagsUnknown` alongside the proven metadata bounds.

The analysis reports invalid parameter declarations and missing or conflicting
factory destinations in the affected node's problems.
`magix key` uses the runtime binding and key construction, including the evaluated
configuration component. See the core package's
[Parameter Configuration](../magix-cache/docs/parameter-configuration.md) guide.
