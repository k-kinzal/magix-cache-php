# Commands

This guide documents every `magix` command, its options, and its output.

## Scanned Paths

Every command reads PHP files without executing them. By default the directories declared in the `autoload` section of the `composer.json` in the current directory are scanned, and `vendor`, `node_modules`, and `.git` are always skipped.

Use `--path` to scan something else. The option can be repeated and accepts directories and single files:

```bash
vendor/bin/magix boundaries --path=src/Query --path=modules/Checkout/src
```

## Boundary References

Commands that take a boundary accept the fully qualified name, the short class name, or the class alone:

```bash
vendor/bin/magix analyze 'App\Query\ProductPageQuery::execute'
vendor/bin/magix analyze ProductPageQuery::execute
vendor/bin/magix analyze ProductPageQuery
```

A reference without a method matches every boundary of the class. `analyze` also accepts uncached methods that make resolvable method calls, including controller actions. When a reference matches several methods, `analyze` renders each separately; `key` only accepts cache boundaries and asks for the fully qualified name when ambiguous.

## magix analyze

Expands a cache boundary or an uncached entry point into its cache dependency tree.

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

The header block describes the boundary itself:

| Field | Meaning |
|---|---|
| `ttl` | Expiration after composition, followed by the reason it differs from the declaration. A number appears only when it is statically determined; a proven range keeps its bounds — `30-60s`, `30-?s` (the `?` is undetermined, not unlimited), `≤60s` — and otherwise the estimate is `unconstrained` (provably no expiration), `unknown` with the runtime condition, or `invalid` when the declaration throws at runtime |
| `strategy` | The `#[UseStrategy]` construction, one line per composed strategy with its contracted candidate range, and `(assumed)` where an explicit `#[AssumeTtl]` replaced the contract. Only shown when a strategy is declared |
| `strategy ttl` | The candidate constraint the composition adds on the normal origin path, before dependencies and the policy cap it; disjoint alternatives stay distinct, such as `30/600-900s` |
| `visibility` | `shared`, `private`, or `nostore` after composition, followed by what restricted it |
| `storable` | Whether the runtime writes an entry for this boundary at all |
| `tags` | Policy tags unioned with the tags of every dependency |
| `key` | The parameters that form the key, the ignored ones, and the policy version |
| `policy` | The declaration as it is written in the source |

Lines below the header show each boundary of the tree, with `!` for a problem that makes the boundary fail and `~` for a note about how the tree was resolved.

Disjoint lifetime contracts such as `#[Ttl(30, new TtlRange(min: 600, max: 900))]` render as `30/600-900s` in the tree, inventory table, and Mermaid output. Parent policies cap each alternative separately: a 300-second parent yields `30/300s`, while an automatic parent preserves `30/600-900s`. The analyzer does not infer the conditions selecting the alternatives or correlations between separate strategies.

JSON retains the existing `state`, `seconds`, `lowerBound`, `upperBound`, and `reason` fields. When alternatives remain disjoint it also includes a normalized `ranges` list, for example `[{"min": 30, "max": 30}, {"min": 600, "max": 900}]`. The enclosing bounds alone do not describe the gaps. An unknown estimate with a proven finite expiration also includes `"finite": true`; an unknown numeric lifetime is not the same as an expiration that might be absent. Single intervals and determined values do not need a `ranges` field.

An action that gathers several cached results for a view does not need `#[Cache]` or `cached()` to be analyzed:

```php
public function show(int $productId, int $viewerId): View
{
    $product = $this->products->execute($productId);
    $stock = $this->inventory->execute($productId);
    $viewer = $this->viewer->execute($viewerId);

    return view('product', [
        'product' => $product->value(),
        'stock' => $stock->value(),
        'viewer' => $viewer->value(),
    ]);
}
```

```bash
vendor/bin/magix analyze ProductController::show
```

The root is labelled `uncached entry point`. Its TTL is the shortest lifetime of the called boundaries, visibility is the strictest, and tags are unioned. For the three queries above, it reports `20s`, `private`, and `inventory, product, viewer`. Unknown bounds and runtime metadata stay unknown. Calls through uncached methods are followed until cache boundaries are reached; those boundaries retain their usual policy analysis. Query objects can be resolved through typed properties, typed action parameters, local constructor assignments, and static calls.

The root's `key` and `policy` are `none (uncached entry point)` and `storable` is `no`, because the action itself does not write a cache entry. This report summarizes the called caches; it does not attach metadata to values extracted with `value()` or configure HTTP caching for the view. Methods that actually call `cached()` still require a cache policy. Uncached entry points are excluded from `boundaries`, `key`, and lint targets.

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--format` | `tree` | `tree`, `json`, or `mermaid` |
| `--depth` | `8` | Maximum dependency depth to expand |

`--format=json` prints the whole tree, including every policy, parameter, effective value, and reason, which suits editors and other tools:

Each node has a `kind` of `boundary` or `entry-point`. Entry points have `policy: null`, `key: null`, and `effective.storable: false`, while `effective` and `dependencies` contain the composed result and its inputs.

```bash
vendor/bin/magix analyze ProductPageQuery::execute --format=json
```

`--format=mermaid` prints a flowchart that can be embedded in documentation:

```text
flowchart TD
    n0["ProductPageQuery::execute<br/>20s - private"]
    n0_0["ProductQuery::execute<br/>20s - shared"]
    n0 --> n0_0
```

## magix boundaries

Lists every boundary of the project with its effective values. The alias `ls` does the same.

```bash
vendor/bin/magix boundaries --filter=Query
```

```text
BOUNDARY                             TTL  VISIBILITY  STORABLE  TAGS              DEPS  LOCATION
App\Query\InventoryQuery::execute     60s  shared      yes       inventory         0     src/Query/InventoryQuery.php:23
App\Query\ProductPageQuery::execute   20s  private     yes       inventory,page    3     src/Query/ProductPageQuery.php:34
App\Query\ViewerQuery::execute        30s  private     yes       viewer            0     src/Query/ViewerQuery.php:25

3 boundaries
```

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--format` | `table` | `table` or `json` |
| `--filter` | none | Only list boundaries whose identifier contains this text |
| `--depth` | `8` | Maximum dependency depth used to compute effective values |

## magix lint

Applies every rule to every boundary and reports what cannot work as declared.

```bash
vendor/bin/magix lint
```

```text
src/Query/DashboardQuery.php:36  warning  unscoped-private-key
  App\Query\DashboardQuery::execute: ViewerQuery::execute is private through $viewerId, but this boundary keys its own entry without that value.
  hint: Accept the value as a parameter and pass it on, or mark it with #[CacheScope] here as well.

1 findings, 0 errors, 1 warnings
```

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--format` | `text` | `text` or `json` |
| `--strict` | off | Fail when warnings are reported as well |

The command exits with `1` when an error is found, and with `1` for warnings when `--strict` is used. Notices never fail the run. See [Lint Rules](lint-rules.md) for every rule.

## magix key

Prints the cache key one call produces. Arguments are given in parameter order and are read as JSON values, so `42` is an integer, `"en"` and `en` are strings, and `{"id":1}` is an array.

```bash
vendor/bin/magix key ProductQuery::execute 42
```

```text
App\Query\ProductQuery::execute
  version    1
  arguments  productId=42
  key        bfc136b0201bb228f9340e6eb474254677bb24f1037a4912ef0f74463ef8173a
```

The arguments line shows the values after `#[CacheIgnore]` and `#[CacheKey]` are applied, and the key is the one `HashCacheKeyStrategy` derives, which makes the entry findable in the cache backend.

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |

> [!NOTE]
> This command loads the referenced class through the Composer autoloader so that `#[CacheKey]` reducers run, and it reports the key of the default strategy. A custom `CacheKeyStrategy` installed on the runtime produces a different key.
