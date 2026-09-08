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

A reference without a method matches every boundary of the class. `analyze` also accepts concrete uncached methods, including controller actions and methods without further calls. When a reference matches several methods, `analyze` renders each separately; `key` only accepts cache boundaries and asks for the fully qualified name when ambiguous.

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
| `storable` | Whether static analysis can prove that this boundary stores its result; `no` also covers runtime-dependent results |
| `tags` | Policy tags unioned with the tags of every dependency |
| `key` | The parameters that form the key, the ignored ones, and the policy version |
| `policy` | The declaration as it is written in the source |

Lines below the header show each boundary of the tree, with `!` for a problem that makes the boundary fail and `~` for a note about how the tree was resolved.

Terminal colors follow the **effective result after composition**:

| Color | Meaning |
|---|---|
| White row | A boundary whose effective result is provably storable, including an automatic `#[Cache]` that carries child constraints upward |
| Gray row | An uncached method, a missing policy, `NoStore`, zero TTL, an invalid declaration, or a result whose storage cannot be proven statically |
| Yellow field | A local setting restricts a result that remains storable |
| Red diagnostic | An invalid lifetime or a problem that makes the declaration fail |

Follow the white rows to see how far cacheable results bubble. `NoStore` turns
the affected parents gray; stored descendants remain white. An uncached entry
point stays gray even when its summary contains a finite TTL from called caches.
Gray can also mean that storage depends on runtime values: read the TTL condition
and visibility label to distinguish uncertainty from a definite stop.
Both `shared` and `private` caches use white; their text labels preserve the
visibility distinction. Notes use gray, and ordinary TTL values follow the row
color.

Yellow fields mark **local restrictions**: a boundary's fixed TTL or `maxTtl`
shortens the composed lifetime, or its policy/scoped parameters impose a stricter
visibility than its dependencies. The header and affected tree nodes use color
alone to identify where local settings limit metadata bubbling while the result
remains storable. Non-storable rows keep their gray color. Tags still union;
adding a tag is not a bubbling stop.

A cap can affect only some TTL alternatives: a composed `30/600-900s` under a
local TTL of 300 seconds is highlighted as `30/300s`.
A policy cap on a declared strategy composition is also highlighted.
Equal or looser settings, ordinary leaf declarations, and inherited restrictions
are not highlighted. Runtime choices, unresolved dependencies, and invalid
declarations are not presented as proven stops; an absent highlight does not prove
that bubbling continues at runtime.

Use `--ansi` to force terminal colors or `--no-ansi` to disable them. Plain text
keeps the same labels and diagnostics without escape codes or extra annotations.
JSON retains the same data. Mermaid keeps its existing yellow styling for local
restrictions; the white/gray row palette applies to the terminal tree.

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
| `--show-uncached` | off | Also show ordinary callees, including leaves and calls inside cache boundaries |
| `--ignore` | none | Hide matching class or `Class::method` subtrees; repeatable and independent of `--show-uncached` |

### Inspecting ordinary calls and hiding subtrees

Use `--show-uncached` to inspect methods that have not been made into cache boundaries:

```bash
vendor/bin/magix analyze PageQuery::execute --show-uncached \
  --ignore 'Inventory*' --ignore '*Manager'
```

Ordinary callees are labelled `uncached`; their lack of a boundary is not an error. They are followed recursively within the scanned sources and the existing `--depth` limit, including concrete methods with no further calls. This uses the same call resolution as the cache analysis: it does not infer database access, execute application code, or discover dynamically named calls and unscanned implementations. A method that calls `cached()` remains a cache boundary and still reports a missing `#[Cache]` policy as a problem.

Without `--show-uncached`, the existing cache tree is retained, including intermediate uncached methods when tracing an uncached entry point. The flag adds inspection paths inside cache boundaries and ordinary leaves. It does not change the computed TTL, visibility, tags, storability, or problems of existing nodes. In particular, following an ordinary helper is not proof that its result carries cache metadata: a helper can extract a plain value with `value()`.

`--ignore` applies to cached and uncached nodes alike, with or without `--show-uncached`. A match hides that node and its entire subtree; descendants are never promoted to the parent. Other paths to the same method remain visible unless they also match. Multiple patterns are combined with OR. The selected root is subject to the same filter: if all roots are ignored, the command succeeds with `[]` in JSON and an explanatory message in the text formats.

Filtering happens after analysis. An ignored dependency still constrains its ancestors' TTL and visibility, contributes tags, and can cause an ancestor to be invalid. Reasons may consequently refer to a hidden dependency. Neither display option changes cache composition or the runtime. The depth limit counts actual method calls before filtering; hidden branches do not free depth for other calls.

| Pattern | Matches |
|---|---|
| `Inventory*` | All methods on short class names beginning with `Inventory` |
| `*Manager` | All methods on short class names ending with `Manager` |
| `InventoryQuery::get` | One exact short class and method name |
| `Inventory*::get*` | Both the class and method patterns |
| `*::get*` | Methods beginning with `get` on any class |
| `App\Query\*` | Fully qualified class names under this namespace, including nested namespaces |
| `App\Query\InventoryQuery::get?` | A fully qualified class and a method ending in exactly one character after `get` |

Patterns match complete names and are case-sensitive. Without `::`, the pattern selects a class's methods. With `::`, class and method patterns are matched separately. A class pattern containing `\` is matched against the fully qualified name; otherwise it is matched against the short class name. An optional leading `\` is accepted for fully qualified names. Patterns apply to the resolved concrete declaration names, including each candidate of an interface call.

Only `*` (zero or more characters) and `?` (one character) are special. `*` also crosses namespace separators; `\` is a literal namespace separator, not a pattern escape. Regexes, character classes, negation and comma-separated lists are not supported; use another `--ignore` for another pattern. Quote patterns as shown above so the shell does not expand them.

The same filtering and labels apply to tree, JSON and Mermaid output.

### Structured output

`--format=json` prints the whole tree, including every policy, parameter, effective value, and reason, which suits editors and other tools:

Each node has a `kind` of `boundary`, `entry-point` (an uncached root), or `uncached` (an ordinary callee). Both uncached kinds have `policy: null`, `key: null`, and `effective.storable: false`. Their `effective` result summarizes the called caches; it does not assert that the method returns metadata. After filtering, the remaining nodes keep their original `effective` results while `dependencies` contains only visible children.

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
