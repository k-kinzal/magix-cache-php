# MagixCache CLI

[![GitHub Actions](https://github.com/k-kinzal/magix-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/k-kinzal/magix-cache/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

`magix` makes the caches of a MagixCache project visible. It reads the source of a project without running it, resolves every `cached()` call site, and bubbles dependencies and applies explicit boundary overrides in the same order as `CacheRuntime` does at runtime.

The result answers the questions that are otherwise only observable in production: what is actually cached, for how long, under which key, and who is allowed to read the entry.

## Features

- Compact cache trees with effective TTL, visibility and tags; detailed declarations, keys and provenance in JSON
- Row selection by `#[Cache]` with `--uncached=between|all|none`, including the selected root, plus repeatable `--ignore` subtree filters
- Partial results during migration: declarations remain visible, known fields stay known, and affected fields show `?` or grounded references such as `10s?`, `shared?` and `tags product?`
- The reason behind each effective value, including inherited metadata and explicit local overrides
- White rows for normal cache boundaries, including runtime-dependent TTL and custom Strategies; gray for ordinary methods, NoStore and TTL 0
- Yellow fields for explicit bubbling overrides; compact markers for confirmed declaration problems, with explanations in JSON
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
ProductPageQuery::execute  ttl 120s  private  tags page
|-- ProductQuery::execute  ttl 20s  shared  tags product
|-- InventoryQuery::execute  ttl 60s  shared  tags inventory
`-- ViewerQuery::execute  ttl 30s  private  tags viewer
```

The boundary explicitly chooses 120 seconds and replaces tags with `page`. Its omitted visibility inherits Private from `ViewerQuery`. The child's 20-second TTL does not cap the parent's explicit override. Nothing needs to be executed to see this.

Tree output uses one line per method, with no warning paragraphs or separate
detail header. Unknown fields show `?`; `10s?` is a reference whose propagation
is unverified, never a value used in parent TTL calculations. Visibility and tags
likewise retain references such as `shared?` and `product?` separately from proven
fields. `tags product,?` guarantees `product`; `tags product?` does not. A proven
Private floor is `≥private`, distinct from the reference `private?`. Proven bounds
remain bounds, and analyzed runtime durations show `dynamic`. Normal declared
rows are white, ordinary methods and disabled results are gray, explicit field
overrides are yellow, and confirmed problems have a compact red marker.
Use `--ansi` or `--no-ansi` to control color.

`--format=json` exports detailed facts in a consistent `roots`/`diagnostics`
envelope. Causes are indexed once and referenced by the fields they affect.
Shared causes and unrelated hidden calls do not fill the overview with warnings.

For an automatic parent, declare `#[Cache]` without a TTL. For a migration that
has added the attribute before connecting `cached()`, the row shows
`[declared]`. JSON keeps the declared policy separate from the actual returned
metadata and records that cache execution has not been observed.

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

The analyzer retains determined values, proven bounds, unconstrained results,
runtime choices, analysis limitations and confirmed invalid declarations
separately. Renderers summarize these facts without changing them. A call that
resolves to several implementations expands into alternatives.

The analyzer follows returned metadata through ordinary methods and cached origin
closures. Returning `Cached` preserves it; `value()` extraction and plain return
values detach it. `map()` preserves its receiver, and `flatMap()`, `zip()`,
`combineN()`, and literal `sequence()` inputs compose their metadata.

Branches (`if`/`elseif`, ternaries, `switch`, and `match`) remain alternatives:
`A or B`, with TTL, visibility, and tags kept together for each result.
Composing a choice with C produces `(A + C) or (B + C)`. Tree and Mermaid show
a compact summary; JSON includes full `metadataAlternatives`. No candidate is selected
by running the application.

Opaque transformations and unsupported control flow retain local causes and
affected-field uncertainty. Returning through an ordinary method alone does
not cause a gap. The default `--uncached=between` retains unattributed methods
only between attributed ancestors and descendants; `all` shows every analyzed
row, and `none` shows only methods with an effective `#[Cache]` attribute.
Return types, observed execution and diagnostics never override selection.
An unattributed selected root can disappear, leaving a forest of declarations.
All formats preserve the effective results of displayed nodes.
See [analysis gaps](docs/commands.md#cache-propagation-gaps) and
[conditional cache results](docs/commands.md#conditional-cache-results).

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
values remain runtime-dependent even when defaults are declared. Parameter TTL overrides replace earlier bounds; JSON strategy labels retain
their source parameters, and dynamic visibility
and tags remain explicit throughout the dependency tree. JSON includes
`visibilityUnknown` and `tagsUnknown` alongside the proven metadata bounds.

The analysis reports invalid parameter declarations and missing or conflicting
factory destinations in the affected node's problems.
`magix key` uses the runtime binding and key construction, including the evaluated
configuration component. See the core package's
[Parameter Configuration](../magix-cache/docs/parameter-configuration.md) guide.
