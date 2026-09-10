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

Analyzes the selected method without executing application code. Partial analysis
is a result: known calls, declarations and metadata remain available even when
another part cannot be followed. Uncertainty belongs to the affected field;
an unrelated ordinary call does not change a cache parent's certainty.

```bash
vendor/bin/magix analyze ProductPageQuery::execute --uncached=none
```

```text
ProductPageQuery::execute  ttl 120s  private  tags page
|-- ProductQuery::execute  ttl 20s  shared  tags product
|-- InventoryQuery::execute  ttl 60s  shared  tags inventory
`-- ViewerQuery::execute  ttl 30s  private  tags viewer
```

The parent declares TTL 120 and tags `page`, so those fields replace the children's
metadata. Omitted visibility inherits Private. The child's TTL does not cap an
explicit parent TTL.

Tree output uses one line per method. It has no separate detail header, repeated
warning paragraphs, or expanded alternative lists. JSON carries those details
for further analysis. Mermaid uses the same compact values in a single diagram,
including when filtering produces several roots.

### Reading partial results

| Label | Meaning |
|---|---|
| `ttl 10s` | A determined effective lifetime |
| `ttl ?` | No usable numeric lifetime is known |
| `ttl 10s?` | A 10-second reference was found in known inputs or a declaration, but its survival into the result is unverified |
| `ttl dynamic` | A finite expiration is established, with its duration decided at runtime |
| `ttl ≤60s`, `30-?s`, `30-60s` | Proven bounds; `?` means undetermined, never unlimited |
| `ttl 30/600-900s` | Disjoint lifetime alternatives, without filling the gap |
| `ttl unconstrained` | Proven absence of an expiration constraint |
| `ttl invalid` | A confirmed invalid expiration declaration |
| `?` in visibility | No usable visibility reference is known |
| `shared?`, `private?`, `nostore?` | Visibility observed before an opaque operation, whose survival is unverified |
| `≥private` | A proven Private floor, with the remaining choice unresolved |
| `tags product?` | A reference to `product`, whose propagation is unverified |
| `tags product,?` | Guaranteed tag `product` and an undetermined remainder |
| `tags page,product?` | Guaranteed tag `page` and tentative tag `product` |
| `tags []?` | An empty tag set observed before an opaque operation; its preservation is unverified |
| `ttl 60s [declared]` | A Cache attribute was read, but this method's cache execution was not observed |
| `[declaration problem]` | A confirmed problem whose explanation is retained in JSON |

A numeric reference such as `10s?` is not a bound or a probability. It appears only
when the understood numeric inputs agree on one value; conflicting references
become `?`. The analyzer never uses references in TTL composition, storage proof,
or an automatic parent's finite-expiration check. A proven bound takes precedence
over a reference in the display. Full reference sources and their basis remain
in JSON, separate from `effective.ttl`.

Visibility and tag references follow the same rule as TTL references. They are
observations for the overview, never effective constraints or storage proof.
For example, `private?` does not establish a Private floor, and `product?` does
not enter the guaranteed tag set. Known facts take display precedence: a proven
Private floor renders as `≥private`, and a guaranteed tag is never suffixed `?`.

Conflicting visibility candidates or tag sets produce `?`; their candidates and
sources remain in JSON. Tag order and duplicates do not create a conflict.
Plain arguments and values detached with `value()` do not supply metadata
references. Explicit field replacements, including runtime parameter overrides
and readable Strategy writers, discard the old reference for that field.
Opaque Strategy replacements can retain metadata observed before that stage.

Only affected fields become uncertain. A fixed TTL can remain `60s` while tags
and visibility retain their own unknown or tentative labels. Explicit parent fields remove their inherited
uncertainty. Runtime parameter settings and readable strategy contracts remain
distinct from syntax or call resolution the analyzer could not follow.

Tags show at most three names, with `+N` for omitted guaranteed names and `+N?`
for omitted tentative names. Full tag lists, key parameters,
strategy candidates, alternative returns, source locations and diagnostic
explanations are available with `--format=json`.

Normal declared rows are white; ordinary methods and effective NoStore or TTL 0
are gray. Explicit local field overrides use yellow. Invalid TTL and the compact
declaration-problem marker use red. An unrelated descendant's diagnostic never
colors the whole parent. White does not prove storage. JSON separately reports
`effective.storage` as `yes`, `no`, `runtime-dependent`, or `unknown`.
`--ansi` and `--no-ansi` change color only.

### Options and row selection

| Option | Default | Purpose |
|---|---|---|
| `--path` | Composer autoload roots | Directory or file to scan, repeatable |
| `--format` | `tree` | `tree`, `json`, or `mermaid` |
| `--depth` | `8` | Maximum original call depth to print; analysis covers the reachable graph |
| `--uncached` | `between` | Select rows according to Cache attributes |
| `--ignore` | none | Hide matching class or `Class::method` subtrees, repeatable |

For this filter, a cache declaration means an effective **`#[Cache]` attribute**:
a method attribute, or the applicable concrete class attribute when the method
has none. A `Cached` return type or a `cached()` call alone does not qualify.

| Mode | Selected rows |
|---|---|
| `all` | Every analyzed method, including wholly unattributed branches |
| `between` | Attributed methods and unattributed methods with both an attributed ancestor and an attributed descendant |
| `none` | Only attributed methods; promote their visible descendants across omitted methods |

Errors and diagnostics never make a row exempt from these rules.
**The selected root follows the same rule.** For example,
`Controller → Helper → CachedB` becomes just `CachedB` under `between` or `none`.
Several attributed descendants can therefore become separate displayed roots.
Use `all` to retain an unattributed entry point.

```bash
vendor/bin/magix analyze ProductController::show --uncached=all
vendor/bin/magix analyze PageQuery::execute --uncached=none --ignore 'Inventory*'
```

`--ignore` removes a matched node and its entire subtree before promotion.
Descendants of an ignored node never reappear. `between` tests the unignored
original hierarchy, independently of display depth; depth counts original calls,
including omitted methods.

Filtering is shared by tree, JSON and Mermaid and happens after analysis.
It changes rows and connections, never the original effective metadata,
alternatives or field certainty. A hidden dependency can still affect a displayed
result. Its cause remains referenced in JSON, but its diagnostic prose is not
reprinted on an ancestor in the tree.

A successful selection with no visible rows produces
`{"roots": [], "diagnostics": []}` in JSON and an explanatory text message.
Missing targets or unreadable required inputs are execution errors. Incomplete
analysis and confirmed declaration problems remain report data and do not make
an otherwise produced report fail.

Ignore patterns match complete, case-sensitive names. Without `::`, they select
all methods of a class; with it, class and method patterns match separately.
A class containing `\` uses its fully qualified name (an optional leading
separator is accepted); otherwise it uses the short name. `*` matches zero or
more characters, including namespace separators, and `?` matches one character.
Patterns apply to resolved concrete candidate names. There are no regular
expressions, negation, or comma-separated lists; repeat `--ignore` for OR.
Quote shell patterns such as `'App\Query\*'` and `'*::get*'`.

### Declaration and execution during migration

Attributes, return types and observed execution are independent facts. A method
with `#[Cache(ttl: 60)]` that returns another method's 10-second `Cached` without
calling `cached()` remains visible under `none` as `ttl 60s [declared]`.
JSON records `declared: true`, `execution: "not-observed"`, the policy and
parameters, and the actual returned effective TTL of 10 seconds. It does not
claim that the declared 60 seconds has been applied or that this method stores.

A method with a `Cached` type but no Cache attribute follows the unattributed
filter rules. A `cached()` call without a policy can have a declaration problem
while still being omitted by `none`. Use `all` and JSON for the complete analyzed
inventory, including such problems.

### Conditional cache results

Returns and local assignments through `if`/`elseif`/`else`, ternaries, `switch`
(including fallthrough and an absent default), and `match` retain exclusive
alternatives. The analyzer does not execute predicates or choose a branch.

If a return chooses A with TTL 20 and tag `a`, or B with TTL 60 and tag `b`,
the TTL summary is `20/60s`. JSON's `metadataAlternatives` retains each
candidate's sources, TTL, visibility and tags. The summary includes only facts
common to every candidate. Tree and Mermaid keep the compact summary.

Composition takes each possible combination: `(A or B)->zip(C)` yields
`(A + C) or (B + C)`. Reusing one selected value, such as `$v->zip($v)`, does
not invent a combination of different alternatives. Separate conditions are
independent unless they reuse that value. Each boundary's settings apply to
every candidate separately. An unbounded branch cannot borrow another branch's
finite expiration to satisfy an automatic policy.

`map()` preserves receiver metadata. `flatMap()`, `zip()`, `combineN()`,
literal `sequence()` inputs, and readable `flatten()`/`unzip()` preserve their
composition semantics. Empty literal `sequence()` or `traverse()` has no
constraint. Runtime iterables remain unknown, including whether they are empty.
Statement branching is bounded to eight levels and metadata expansion to
128 candidates per operation; reaching these limits creates a local cause.

### Cache propagation gaps

Call structure and metadata propagation are separate results. A known call
remains a dependency even if an opaque transformation prevents the analyzer
from proving which metadata is returned. Simply crossing an ordinary method
does not cause a gap: returning `Cached` preserves metadata and extracting
`value()` detaches it. `Cached::of($value)` adds no metadata; explicitly supplying
a child's metadata preserves its constraints.

For an automatic parent returning
`opaqueTransform($this->query->execute())` through an ordinary helper, a compact
report can show:

```text
PageQuery::execute  ttl 20s?  shared?  tags product?
`-- ProductQuery::execute  ttl 20s  shared  tags product
```

The TTL, visibility and tag references do not assert propagation. An explicit parent TTL
would replace that field while other affected fields remain uncertain.

JSON retains source causes, affected-field references, original call resolution,
and `analysisGaps` with full method paths. Sources shared by many callers are
indexed once in report-level `diagnostics`. Hidden unrelated causes do not
reappear merely because they are descendants. Hidden causes actually affecting
a displayed field remain available by ID.

Calls are limited to statically resolved methods in scanned sources. Unresolved
call sites remain in `calls`; recursion retains uncertainty without inventing
unseen children. Absence of a gap is not proof that every runtime dependency was
found.

### Structured output

```bash
vendor/bin/magix analyze ProductPageQuery::execute --format=json --uncached=all
```

JSON always has the same envelope, with zero, one or several roots:

```json
{
  "roots": [],
  "diagnostics": []
}
```

Each root and dependency contains:

| Field | Content |
|---|---|
| `boundary`, `file`, `line` | Method identity and source location |
| `declared`, `policy` | Attribute presence and the read declaration, including unknown-field flags |
| `execution`, `returnType`, `parameters`, `hasDynamicTtl`, `useStrategy` | Execution observation and independent source declarations |
| `kind` | `boundary` for observed execution, otherwise `entry-point` at a displayed root or `uncached` below one |
| `calls` | Original targets, unresolved method names, candidate methods, source lines and resolution state, independently of displayed children |
| `via` | Original callers omitted from the displayed connection |
| `key`, `strategy` | Applied key parameters and analyzed strategy construction/steps |
| `effective` | Original TTL, visibility, tags, storage proof, local overrides and confirmed problems |
| `effective.certainty` | Per-field `known`, `unconstrained`, `invalid`, `runtime`, or `partial` as applicable |
| `effective.analysis` | Per-field cause IDs and separate `ttlReference`, `visibilityReference` and `tagsReference` records |
| `metadataAlternatives` | Distinct returned candidates, sources and per-candidate effective analysis |
| `diagnostics` | IDs of local analysis causes, including observations superseded by explicit field overrides |
| `analysisGaps`, `notes` | Detailed propagation paths and traversal observations |
| `dependencies` | Visible child nodes after selection |

Report-level `diagnostics` defines each referenced cause once with `id`, `kind`,
`method`, `file`, `line`, and `message`. Consumers can index the field references
to count causes separately from affected methods. Ordinary call resolution
remains independent of metadata certainty.

`visibilityReference` and `tagsReference` include `value`, `candidates`, `sources`
and `basis`. A visibility value uses its lower-case name; a tag value is an
array of names. When candidates disagree, `value` is `null` and the conflicting
`candidates` remain available. These records stay separate from
`effective.visibility`, `effective.tags`, their unknown flags and storage proof.
An explicit replacement removes only the corresponding reference.

`useStrategy.arguments` retains tagged declaration values: `unknown`, `runtime`
parameter references, or `known` literals, arrays and enum cases. Unreadable
arguments never turn the whole report into a JSON encoding failure.

The TTL domain keeps `state`, `seconds`, `lowerBound`, `upperBound`, and `reason`.
Disjoint estimates also include normalized `ranges`; enclosing bounds alone do
not describe their gaps. Unknown numeric durations with proven finite expiration
include `finite: true`. A reference does not populate `seconds`, bounds or
finite-expiration proof.

This envelope replaces the previous single-node/multiple-node JSON shape;
consumers should read `roots` and resolve diagnostic IDs against `diagnostics`.
The JSON projection retains full details of selected nodes, including causes
from hidden methods that their results still depend on. `--uncached=all` and a
sufficient `--depth` expose all analyzed rows.

`--format=mermaid` emits one flowchart for the forest. Dotted connections span
omitted methods; an edge describes a call path, not proof of metadata propagation.

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
`(+1 day)` marker. JSON retains each strategy step's local candidate, while
`expires by` shows the surviving daily constraint in the overview. Other
constraints can expire the result earlier. A parent with an explicit 60-second TTL replaces inherited clock constraints
and shows `60s`; lower-priority candidates remain in JSON.

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


TTL/ExpiresAt contracts describe expiration only. Readable strategies preserve other fields unless `#[WritesMetadata]` declares their replacement; those replacement values remain runtime-dependent. Unreadable strategies retain analysis uncertainty. `#[AssumeTtl]` covers expiration alone, and later parent settings can explicitly replace affected fields.
