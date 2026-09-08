# Commands

This guide documents every `magix` command, its options, and its output.

## Scanned Paths

Every command scans PHP files as source. `key` additionally loads the selected class and executes its key reducers and strategy factories. By default the directories declared in the `autoload` section of the `composer.json` in the current directory are scanned, and `vendor`, `node_modules`, and `.git` are always skipped.

Use `--path` to scan something else. The option can be repeated and accepts directories and single files:

```bash
vendor/bin/magix analyze ProductPageQuery::execute --path=src/Query --path=modules/Checkout/src
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

Lines below the header show each boundary of the tree, with `!` for a problem that makes the boundary fail, `~` for a note about how the tree was resolved, and `#` for a comment written with `#[CacheComment]`.

Yellow fields mark **local restrictions**: a boundary's fixed TTL or `maxTtl`
shortens the composed lifetime, or its policy/scoped parameters impose a stricter
visibility than its dependencies. The header and every affected tree node retain
the explanation, for example `10s [local restriction: local ttl 10s; composed 60s]`.
This identifies where local settings limit metadata bubbling. Tags still union;
adding a tag is not a bubbling stop.

A cap can affect only some TTL alternatives: a composed `30/600-900s` under a
local TTL of 300 seconds is highlighted as `30/300s`, with the original alternatives
in the explanation. A policy cap on a declared strategy composition is also shown.
Equal or looser settings, ordinary leaf declarations, and inherited restrictions
are not highlighted. Runtime choices, unresolved dependencies, and invalid
declarations are not presented as proven stops; an absent highlight does not prove
that bubbling continues at runtime.

Use `--ansi` to force terminal colors or `--no-ansi` to disable them. The
`[local restriction: ...]` explanations remain readable without color. JSON includes
an `effective.localRestrictions` map with `ttl` and/or `visibility` explanations
(an empty array when none are proven). Mermaid colors affected nodes yellow and
names their restricted fields.

Disjoint lifetime contracts such as `#[Ttl(30, new TtlRange(min: 600, max: 900))]` render as `30/600-900s` in the tree and Mermaid output. Parent policies cap each alternative separately: a 300-second parent yields `30/300s`, while an automatic parent preserves `30/600-900s`. The analyzer does not infer the conditions selecting the alternatives or correlations between separate strategies.

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

The root's `key` and `policy` are `none (uncached entry point)` and `storable` is `no`, because the action itself does not write a cache entry. This report summarizes the called caches; it does not attach metadata to values extracted with `value()` or configure HTTP caching for the view. Methods that actually call `cached()` still require a cache policy. Uncached entry points cannot be selected by `key`.

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

### Migration comments

Use `Magix\Cache\Attribute\CacheComment` to leave a note that remains visible in
`analyze` while a migration or verification is unfinished:

```php
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\CacheComment;
use Magix\Cache\Cached;
use Magix\Cache\Metadata\Visibility;

#[Cache(visibility: Visibility::NoStore)]
#[CacheComment('既存準拠で NoStore。本来は Bubbling を止める必要なし')]
public function execute(): Cached
{
    return $this->cached(fn () => $this->products->execute());
}
```

The tree places the note directly below that boundary, including when it appears
as a dependency:

```text
ProductPageQuery::execute  ttl 20s (declared Ttl::Auto)  nostore [local restriction: declared by the policy; composed shared]
    # 既存準拠で NoStore。本来は Bubbling を止める必要なし
`-- ProductQuery::execute  ttl 20s  shared
```

For the opposite migration state, use a comment such as
`#[CacheComment('既存は NoStore。Bubbling を有効にして検証中')]` on the boundary
whose policy now permits bubbling.

The attribute accepts one string, positional or named (`comment: '...'`), on a
class or method, including an uncached entry point. A class comment is the
default for its declared methods; a method comment replaces it as a whole.
`#[CacheComment('')]` hides the default for that method. Parent-class comments
are not inherited implicitly. Use one attribute per declaration.

Comments describe intent only: they do not change TTL, visibility, tags, cache
keys, or analysis problems, and they do not suppress restriction highlights.
They stay attached to their own nodes rather than bubbling as metadata.

String literals, including multiline strings, are read without running the
application. Expressions the reader cannot resolve, such as application
constants, display `(unresolved #[CacheComment])` instead of executing code or
silently using the class default. Tree comments are cyan with ANSI enabled and
retain their `#` prefix without color. Console markup in the text is literal.
JSON includes a separate `comment` field on each node (`null` if absent, `""`
if explicitly hidden); Mermaid includes an escaped comment in the node label.

## magix key

Prints the cache key one call produces. Arguments are given in parameter order and are read as JSON values, so `42` is an integer, `"en"` and `en` are strings, and `{"id":1}` is an array.

```bash
vendor/bin/magix key ProductQuery::execute 42
```

```text
App\Query\ProductQuery::execute
  version    1
  namespace  magix
  arguments  productId=42
  key        bfc136b0201bb228f9340e6eb474254677bb24f1037a4912ef0f74463ef8173a
```

The arguments line shows the values after `#[CacheIgnore]` and `#[CacheKey]` are applied. The key is the one `HashCacheKeyStrategy` derives using the runtime namespace, `magix` by default. If the application configures `new CacheRuntime($cache, namespace: 'catalog-v2')`, supply the same namespace:

```bash
vendor/bin/magix key ProductQuery::execute 42 --namespace=catalog-v2
```

The namespace is a runtime setting, separate from the runtime reference named in `#[Cache(runtime: '...')]`. This command does not bootstrap the application or read registered runtimes, so a custom namespace must be supplied explicitly. An explicitly empty namespace is supported with `--namespace=`.

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--namespace` | `magix` | Key namespace configured on `CacheRuntime` |

> [!NOTE]
> This command loads the referenced class through the Composer autoloader and runs its `#[CacheKey]` reducers and strategy factories. It does not call the boundary body or read or write cache entries. It reports the default hash strategy's key; a custom `CacheKeyStrategy` installed on the runtime is not loaded and may produce a different key.
