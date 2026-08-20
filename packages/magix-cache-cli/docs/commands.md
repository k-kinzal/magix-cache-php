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

A reference without a method matches every boundary of the class. When a reference matches several boundaries, `analyze` renders all of them and `key` asks for the fully qualified name.

## magix analyze

Expands one boundary into the tree of boundaries it depends on.

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

The header block describes the boundary itself:

| Field | Meaning |
|---|---|
| `ttl` | Expiration after composition, followed by the reason it differs from the declaration |
| `visibility` | `shared`, `private`, or `nostore` after composition, followed by what restricted it |
| `storable` | Whether the runtime writes an entry for this boundary at all |
| `tags` | Policy tags unioned with the tags of every dependency |
| `key` | The parameters that form the key, the ignored ones, and the policy version |
| `policy` | The declaration as it is written in the source |

Lines below the header show each boundary of the tree, with `!` for a problem that makes the boundary fail and `~` for a note about how the tree was resolved.

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--format` | `tree` | `tree`, `json`, or `mermaid` |
| `--depth` | `8` | Maximum dependency depth to expand |

`--format=json` prints the whole tree, including every policy, parameter, effective value, and reason, which suits editors and other tools:

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
