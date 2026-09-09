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
| `strategy ttl` | The winning Strategy expiration override on the normal origin path; disjoint alternatives stay distinct, such as `30/600-900s` |
| `visibility` | `shared`, `private`, or `nostore` after composition, followed by what restricted it |
| `storable` | `yes` when storage is proven, `no` for a non-cache method, a disabled cache, or an invalid declaration, `runtime-dependent` for a normal runtime decision, and `unknown (analysis incomplete)` when storage is unproven and call analysis is unfinished |
| `tags` | Bubbled dependency tags, replaced when the boundary explicitly sets tags |
| `key` | The parameters that form the key, the ignored ones, and the policy version |
| `policy` | The declaration as it is written in the source |

Lines below the header show each boundary of the tree, with `!` for a declaration problem and `~` for incomplete analysis or a note about how the tree was resolved. An analysis gap does not assert that the boundary fails at runtime.

Terminal colors describe **Magix Cache behavior**, independently of whether static
analysis proves that a particular invocation will store an entry:

| Color | Meaning |
|---|---|
| White row | A normal cache boundary, including dynamic TTL, parameter configuration, daily expiration and custom Strategies |
| Gray row | An ordinary method, an uncached entry point, or a boundary whose effective result has `NoStore` or TTL 0 |
| Yellow row / `~` diagnostic | Incomplete call analysis: depth limits, recursion, ambiguous implementations or unverified propagation through ordinary methods |
| Yellow field | An explicit local override of bubbled TTL, visibility or tags on an enabled, valid boundary |
| Red row / `!` diagnostic | A definite declaration error, such as a missing policy, invalid lifetime or invalid Strategy binding |

White means normal cache participation, not guaranteed storage. Runtime TTL and
visibility can decide whether a particular call is stored; custom Strategy
metadata stays unknown in the analysis without turning normal behavior into a
warning. `shared` and `private` both use white. TTL 0 is a valid non-storage
setting, so it uses gray like `NoStore`, not error red.

Ordinary descendants display `(uncached)` without TTL or visibility fields;
NoStore boundaries retain their metadata and show `nostore`. An explicitly
selected uncached root remains visible as `(uncached entry point)` with its
summary of called caches. Errors take precedence over gray and yellow. Disabled
caches and ordinary methods retain gray rows even when they carry yellow warnings.

Yellow analysis warnings name the affected method or call path. For a depth limit,
raise `--depth`; recursion and unverified metadata propagation require inspecting
the reported path and cannot be solved just by increasing depth. Warnings remain
visible when their source rows are hidden by `--uncached` or `--ignore`. Affected
cache ancestors stay yellow because filtering does not complete the analysis.
Each warning appears at its lowest visible ancestor to avoid repeating it at
every level. Informational labels and ordinary TTL values follow the row color.

Yellow fields mark **local overrides** when a boundary has dependencies.
Explicit settings are shown even when numerically equal to inherited values:
they still own that field. This includes runtime-dependent overrides and
Strategies. Ordinary leaf declarations have no dependency bubbling to override.
FromUpstream is highlighted when its maximum changes a proven lifetime. Invalid
and disabled boundaries retain their red or gray fields.

Use `--ansi` to force terminal colors or `--no-ansi` to disable them. Plain text
keeps the same labels and diagnostics. Mermaid uses the same row meanings,
coloring the whole node yellow when a white node contains override fields; errors
remain red and disabled nodes remain gray. JSON preserves conservative
`effective.storable` proof (`false` still includes runtime decisions), and adds
`analysisWarnings` independently of declaration `problems` and `analysisGaps`.
Neither color nor the terminal storage label changes the computed metadata.

Disjoint lifetime contracts such as `#[Ttl(30, new TtlRange(min: 600, max: 900))]` render as `30/600-900s` in the tree and Mermaid output. A fixed 300-second parent yields `300s`, while an automatic parent preserves `30/600-900s`. FromUpstream caps each alternative separately. The analyzer does not infer the conditions selecting the alternatives or correlations between separate strategies.

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

The root is labelled `uncached entry point`. Its effective metadata describes what the method returns. In the example above, `value()` extracts plain values, so the root has `unconstrained` TTL, `shared` visibility, and no tags. The child queries remain visible with their own cache policies. An ordinary method returning a `Cached` intact preserves that result's metadata. Query objects can be resolved through typed properties, typed action parameters, local constructor assignments, and static calls.

The root's `key` and `policy` are `none (uncached entry point)` and `storable` is `no`, because the action itself does not write a cache entry. The report does not attach metadata to values extracted with `value()` or configure HTTP caching for the view. Methods that actually call `cached()` still require a cache policy. Uncached entry points cannot be selected by `key`.

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--format` | `tree` | `tree`, `json`, or `mermaid` |
| `--depth` | `8` | Maximum dependency depth to expand |
| `--uncached` | `between` | Ordinary method rows: `between` cache boundaries, `all`, or `none` |
| `--ignore` | none | Hide matching class or `Class::method` subtrees; repeatable and independent of `--uncached` |

### Inspecting ordinary calls and hiding subtrees

Choose how many ordinary method rows to display:

| Mode | `CachedA → Helper → CachedB` | `CachedA → Helper → Leaf` (both ordinary) |
|---|---|---|
| `--uncached=between` (default) | Keep `Helper` between the cache boundaries | Omit the wholly uncached branch |
| `--uncached=all` | Keep `Helper` | Show the entire branch, including leaves |
| `--uncached=none` | Omit `Helper` and display `CachedB` under `CachedA` | Omit the wholly uncached branch |

The explicitly selected root remains visible in every mode, including an uncached entry point. In `between` and `none`, ordinary methods before the first cache boundary are also omitted: `Controller → Helper → CachedB` displays `Controller → CachedB`. The displayed connections can therefore span omitted calls; they do not prove direct calls or metadata propagation.

Use `--uncached=all` to inspect methods that have not been made into cache boundaries:

```bash
vendor/bin/magix analyze PageQuery::execute --uncached=all \
  --ignore 'Inventory*' --ignore '*Manager'
```

Ordinary callees are labelled `uncached`; their lack of a boundary is not an error. They are followed recursively within the scanned sources and the existing `--depth` limit, including concrete methods with no further calls. This uses the same call resolution as the cache analysis: it does not infer database access, execute application code, or discover dynamically named calls and unscanned implementations. A method that calls `cached()` remains a cache boundary and still reports a missing `#[Cache]` policy as a problem.

The default `between` mode keeps only ordinary methods with both a cached ancestor and a cached descendant on the analyzed path. Ordinary side branches are omitted even when they hang off a visible intermediate method. These cache-to-cache paths are reported as analysis gaps. Every mode preserves the computed TTL, visibility, tags, storability, problems, and gaps of existing nodes. Following an ordinary helper is not proof that its result carries cache metadata: a helper can extract a plain value with `value()`.

`--ignore` applies to cached and uncached nodes alike in every mode, before ordinary rows are omitted. A match hides that node and its entire subtree; descendants of an ignored node are never promoted to the parent, even with `--uncached=none`. Other paths to the same method remain visible unless they also match. Multiple patterns are combined with OR. The selected root is subject to the same filter: if all roots are ignored, the command succeeds with `[]` in JSON and an explanatory message in the text formats.

Filtering happens after analysis and is shared by tree, JSON, and Mermaid output. An ignored dependency still constrains its ancestors' TTL and visibility, contributes tags, and can cause an ancestor to be invalid. Analysis gaps and uncertainty also remain on the affected parent when their paths are hidden, including with `--uncached=none`. Diagnostics may consequently name a hidden ordinary method. Neither display option changes cache composition or the runtime. The depth limit counts actual method calls before filtering; hidden branches do not free depth for other calls.

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

### Conditional cache results

The analyzer follows returns and local assignments through `if`/`elseif`/`else`,
ternaries, `switch` (including fallthrough and an absent default), and `match`.
It does not execute conditions or choose a runtime branch. For example:

```php
public function choose(bool $flag): Cached
{
    return $flag ? $this->a->execute() : $this->b->execute();
}
```

If A returns 20 seconds with shared visibility and tag `a`, while B returns
60 seconds with private visibility and tag `b`, the report retains both:

```text
alternatives: A::execute [ttl 20s, shared, tags a] or B::execute [ttl 60s, private, tags b]
```

The TTL summary is `20/60s`, not `20s`. Only facts common to every branch appear
in the summary's tags and visibility; each candidate retains its exact fields.
This also works inside a `cached()` origin. An ordinary method still displays
`(uncached)` and never stores its own result.

Composition uses each possible combination: `(A or B)->zip(C)` yields
`(A + C) or (B + C)`. Reusing the same selected value, such as `$v->zip($v)`,
does not invent a combination of different alternatives. Separate conditions
are independent unless they reuse that same value; the analyzer does not prove
relationships between arbitrary predicates. Boundary settings are applied to
every candidate separately. Even when settings make their metadata equal,
distinct dependency selections remain visible. A branch without a finite
expiration cannot borrow one from another branch to satisfy an automatic TTL.

`map()` preserves its receiver's metadata. `flatMap()`, `zip()`, `combineN()`,
literal `sequence()` inputs, and readable `flatten()`/`unzip()` expressions
preserve their composition semantics. A literal empty `sequence()` or `traverse()`
has no constraint. A runtime iterable remains unknown, including whether it is
empty. Opaque callbacks, unsupported statements, mutated bindings that cannot be
followed, and excessive path expansion remain unanalyzed. Statement branching is
bounded to eight levels and metadata expansion to 128 candidates per operation.

### Cache propagation gaps

An ordinary method between two cache boundaries does not by itself cause a gap.
Returning `Cached` intact propagates its metadata. Extracting `value()`, including
through local aliases, returns a plain value without that metadata. For example,
`return $this->query->execute()->value()` is uncached; its query remains visible
under `--uncached=between`, and no propagation diagnostic is emitted. Rewrapping
with `Cached::of($value)` adds no metadata; explicitly supplying a child's
`metadata` preserves that child's constraints.

A gap remains when the returned metadata cannot be followed, such as
`return opaqueTransform($this->query->execute())`. The report then includes:

```text
PageQuery::execute  ttl 60s  shared or stricter  tags runtime tags
    ~ cache propagation unanalyzed: PageQuery::execute -> ProductLookup::get -> ProductQuery::execute
`-- ProductLookup::get (uncached)
    `-- ProductQuery::execute  ttl 20s  shared  tags product
```

The child's metadata is not assumed to propagate through an opaque transformation.
Unknown TTL, visibility, and tags remain subject to the parent's explicit settings.
The warning describes incomplete analysis, not an invalid declaration. A known
extraction is analyzed even though it deliberately drops the child's metadata.

Detection is limited to resolved calls in scanned sources within `--depth`.
Recursion and depth cutoffs retain uncertainty without inventing unseen children.
Use `--uncached=all` to inspect ordinary paths. No gap is not proof that every
runtime dependency has been found. Filtering never removes the original metadata
alternatives or diagnostics, including when their source nodes are hidden.

JSON includes an `analysisGaps` list on every node. Each gap has
`kind: "unverified-cache-propagation"`, a `path` of fully qualified method IDs,
and a readable `message`, separate from invalid `effective.problems`.
Mermaid includes the diagnostic path and styles an affected cache parent yellow.
`analyze` continues to succeed when it produces a report with analysis gaps.

### Structured output

`--format=json` prints the whole tree, including every policy, parameter, effective value, and reason, which suits editors and other tools:

Each node has a `kind` of `boundary`, `entry-point` (an uncached root), or `uncached` (an ordinary callee). Both uncached kinds have `policy: null`, `key: null`, and `effective.storable: false`. Their `effective` result describes returned metadata; known extraction yields no constraints. `metadataAlternatives` retains each possible result with its sources, TTL, visibility, tags, storage proof, and analysis status. The top-level `effective` result summarizes only facts shared by those alternatives. After filtering, the remaining nodes keep their original `effective` results while `dependencies` contains only visible children.

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

## Wall-clock expiration contracts

`analyze` reads `#[ExpiresAt('12:00', until: '12:15', timezone: 'Asia/Tokyo')]`
on a strategy's `fetch()` and displays `daily 12:00-12:15 Asia/Tokyo` separately
from TTL seconds. Omit `until` for a single time; overnight ranges retain a
`(+1 day)` marker. The `strategy at` row describes the local candidate, while
`expires by` propagates its upper constraint through dependencies. Other
constraints can expire the result earlier. A parent with a 60-second TTL shows
`≤60s` and keeps the wall-clock window.

Repeat `#[ExpiresAt]` on the same `fetch()` for multiple times or windows, for
example `#[ExpiresAt('09:00')]` followed by `#[ExpiresAt('18:00', until: '18:15')]`.
All declarations are read and bound independently, including their timezones
and constructor references. Tree and Mermaid display
`earliest of (daily 09:00 UTC; daily 18:00-18:15 UTC)`; JSON retains both entries
through nested compositions and parent policies. These simultaneous constraints
meet at the earliest selected absolute expiration. Errors in any declaration
are reported as strategy problems.

Tree summaries and Mermaid nodes include the time constraints. JSON preserves
structured clock fields in `strategy.expirations`, each strategy step's
`expirations`, and `effective.expirationConstraints`. Those optional keys are
omitted when no clock contract applies. The ordinary `ttl` fields remain
relative durations, unknown until the origin time is available. A valid clock
contract supplies finite-expiration proof to automatic parent policies.

See [Daily Expiration Times and Distribution Windows](../../magix-cache/docs/cache-strategies.md#daily-expiration-times-and-distribution-windows)
for constructor references, invocation-dependent values, composition, timezone
semantics, and the responsibility of the strategy implementation.


The CLI's TTL/ExpiresAt contracts describe expiration only. For custom Strategies, tags and visibility remain unknown because arbitrary metadata overrides are not proven by those contracts. The bundled KeySpreadExpirationStrategy and StaleIfErrorCacheStrategy preserve those fields on normal origin success. A later parent can explicitly replace unknown fields; AssumeTtl only resolves expiration, never other metadata.
