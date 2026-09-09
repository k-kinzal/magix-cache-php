# MagixCache CLI

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

`magix` makes the caches of a MagixCache project visible. It reads the source of a project without running it, resolves every `cached()` call site, and bubbles dependencies and applies explicit boundary overrides in the same order as `CacheRuntime` does at runtime.

The result answers the questions that are otherwise only observable in production: what is actually cached, for how long, under which key, and who is allowed to read the entry.

## Features

- Cache trees for one boundary, with the effective TTL, visibility, tags, and key of every node
- Ordinary method display with `--uncached=between|all|none` (default: `between` cache boundaries), and independent subtree filters with repeatable `--ignore` patterns
- Explicit analysis gaps when a cache boundary reaches another cache through ordinary methods, with unverified metadata kept unknown
- The reason behind each effective value, including inherited metadata and explicit local overrides
- White rows for normal cache boundaries, including runtime-dependent TTL and custom Strategies; gray for ordinary methods, NoStore and TTL 0
- Yellow fields for explicit bubbling overrides, yellow warnings for incomplete analysis, and red for definite declaration errors
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

  ttl          120s
  visibility   private (inherited from ViewerQuery::execute)
  storable     yes
  tags         page
  key          $productId, $viewerId (ignored: $trace)  version 1
  policy       #[Cache(ttl: 120s, tags: [page])]

ProductPageQuery::execute  ttl 120s  private  tags page
|-- ProductQuery::execute  ttl 20s  shared  tags product
|-- InventoryQuery::execute  ttl 60s  shared  tags inventory
`-- ViewerQuery::execute  ttl 30s  private  tags viewer
```

The boundary explicitly chooses 120 seconds and replaces tags with `page`. Its omitted visibility inherits Private from `ViewerQuery`. The child's 20-second TTL does not cap the parent's explicit override. Nothing needs to be executed to see this.

In a color terminal, normal cache boundaries appear white, including dynamic TTL, parameter configuration and custom Strategies. Ordinary methods, effective `NoStore` and TTL 0 use gray; missing policies and invalid declarations use red. Incomplete call analysis uses yellow with a diagnostic; depth limits explain how to increase `--depth`. Explicit overrides of bubbled fields also use yellow. `shared` and `private` retain their text labels without separate colors. White describes normal behavior rather than guaranteed storage: the header distinguishes `runtime-dependent` from `no`. Mermaid uses the same meanings. Use `--ansi` to force terminal colors or `--no-ansi` for plain text.

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

The composition rules are the ones the runtime applies: the earliest expiration wins, cacheability is combined with logical AND, the strictest visibility wins, and tags are unioned. These are bubbling rules. Explicit parent fields override afterward: fixed TTL replaces expiration, tags replace the tag list, and visibility replaces scope. Strategies override after policy and invocation settings.

Because the analysis is static, the effective TTL is an honest estimate rather than a guess. A lifetime is reported as a number only when it is statically determined; a boundary that is provably without expiration is `unconstrained`; anything that depends on runtime values, such as a `#[DynamicTtl]` resolver or an upstream the analyzer cannot see, is `unknown`, together with the tightest provable upper bound such as `unknown (≤30s)`; and a declaration that throws at runtime is `invalid`. A call that resolves to several implementations expands into all of them.

The analyzer follows returned metadata through ordinary methods and cached origin
closures. Returning `Cached` preserves it; `value()` extraction and plain return
values detach it. `map()` preserves its receiver, and `flatMap()`, `zip()`,
`combineN()`, and literal `sequence()` inputs compose their metadata.

Branches (`if`/`elseif`, ternaries, `switch`, and `match`) remain alternatives:
`A or B`, with TTL, visibility, and tags kept together for each result.
Composing a choice with C produces `(A + C) or (B + C)`. Tree and Mermaid show
these candidates; JSON includes `metadataAlternatives`. No candidate is selected
by running the application.

Opaque transformations or unsupported control flow can still produce
`cache propagation unanalyzed`; merely crossing an ordinary method does not.
The default `--uncached=between` shows intermediate methods; `all` also shows
wholly uncached branches, and `none` omits ordinary rows. These display choices
preserve alternatives, effects, and diagnostics. See [analysis gaps](docs/commands.md#cache-propagation-gaps)
and [conditional cache results](docs/commands.md#conditional-cache-results).

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
