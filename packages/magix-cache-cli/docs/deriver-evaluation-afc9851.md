# Deriver afc9851 caller-pruning evaluation

Evaluated on 2026-10-19 against upstream main
`afc98516f758f78c6c43d96e084c040d684b3fb3` (rechecked before publishing).
The package is `k-kinzal/deriver`; its CLI is named `deliver`.

## Decision

**Caller pruning is useful and the previously reported exception-region gaps
are fixed in these probes. Latest Deriver cannot currently be installed into
Magix under the packages' declared dependency constraints. An isolated API
migration preserves all characterized outputs, but is slower than the existing
integration. Do not treat this as a completed production dependency update.**

The production lock remains on `e59f993`. The new public API was exercised using
an unmodified, separate installation of `afc9851`. A migration patch is retained
as an experiment, rather than merging code that cannot be installed normally.
The PR is draft pending an installable migration and its required validation.

| Area | Result |
|---|---|
| Normal Composer update | Blocked: latest requires Console `^6.4`; Magix requires `^7.4 \|\| ^8.0` |
| Caller-path selection | Correct in the tested direct, ancestor, method, case-normalized and correlated-call cases |
| Work avoided by pruning | 16 unrelated argument evaluations become zero; query median 1.816 ms becomes 0.203 ms |
| Previous exception feedback | Static continuation values retained; throw-only no longer invents concrete null |
| Magix behavior with an API-only overlay | 33 frozen reports, 3,072 numeric comparisons and all 3,497 tests pass |
| Magix performance with that prototype | Slower: 200-declaration scan median 409 ms becomes 909 ms |
| Complete analysis of arbitrary PHP | Not established; partial dependencies remain explicit and are valid results |

## Changes reviewed

The [upstream comparison](https://github.com/k-kinzal/deriver/compare/e59f993c3d84d23066772b85e1226a66a2d16ae6...afc98516f758f78c6c43d96e084c040d684b3fb3)
contains candidate-contract fixes and two substantial refactors. The previous
`Analyzer`, `ProjectInput`, `SourceFile`, entry-point binding and assessment API
has been replaced by `Deriver::prepare($paths, $config)` and `derive($selector)`.
Results are `CandidateCollection` values containing analyzed native values or
partial terms, evidence, statistics and an `interrupted` flag.

The [query contract](https://github.com/k-kinzal/deriver/blob/afc98516f758f78c6c43d96e084c040d684b3fb3/docs/queries.md)
explicitly describes source candidates. Default discovery keeps both arms of
`false ? 30 : 60`; `BranchEvaluators::truth()` requests predicate evaluation and
pruning. Loop recurrences remain dependencies rather than being executed to
obtain their final iteration value. Neither behavior is treated as a regression
against execution semantics. The relevant questions are whether source values
are retained, dynamic dependencies are visible, and the consumer can select the
intended invocation contexts without inventing argument combinations.

The [evaluator API](https://github.com/k-kinzal/deriver/blob/afc98516f758f78c6c43d96e084c040d684b3fb3/docs/evaluators.md)
supports lazy application-defined evaluation. Caller-path selection restricts
caller discovery before actual arguments are derived. `forCaller()` instead
filters already-derived evidence and retains unconstrained candidates; it is
not proof that a matching invocation exists.

## Installation blocker and evaluation environments

Running `composer update k-kinzal/deriver` in the worktree fails with:

```text
k-kinzal/deriver dev-main requires symfony/console ^6.4
but it conflicts with your root composer.json require (^7.4 || ^8.0).
```

`--with-all-dependencies` cannot make disjoint root requirements compatible.
Both the root and the Magix CLI package require the newer Console range.
Latest Deriver also requires YAML `^6.4`; Console is the demonstrated hard
conflict. No dependency metadata was edited or aliased to hide the conflict.

Two environments were deliberately kept distinct:

1. **Official standalone installation:** Deriver `afc9851`, Console/YAML 6.4.48,
   PHP-Parser 5.9.0. All candidate and pruning probes use this installation.
2. **API-only Magix experiment:** the existing Magix vendor installation and
   Console 7.4.18, with only the `Deriver\\` autoload prefix redirected to the
   unmodified latest source, plus the migration patch. This bypasses dependency
   resolution for an experiment. Its successful tests do not establish a
   supported Composer installation or the upstream suite's Console compatibility.

Trying the migration with the standalone Console 6.4 instead causes all 33
frozen report cases to error: Magix's `Application::addCommand()` does not exist
in that Console version. Downgrading Magix is therefore not a lockfile-only fix.
Upstream should test and allow current Console versions, or separate the
library's dependency surface from the CLI. This is the highest-priority feedback.

The host is PHP 8.5.8. Deriver uses PHP 8.3 target semantics; PHP-Parser stays at
5.9.0. No internal Deriver budgets were increased.

## Caller pruning: correctness and actual avoided work

The [standalone reproducer](deriver-pruning-reproducer.php.example) records 36
source cases in structural and truth-pruned modes, 15 path queries in each
mode, and post-filter examples. The [checker](deriver-pruning-checks.py) verifies
59 semantic assertions. The [JSON evidence](deriver-evaluation-afc9851.json)
contains compact results, stopping reasons, statistics and benchmark samples.

| Query or condition | Observed result |
|---|---|
| `page -> bridge -> ttl#return`, with truth evaluator | 30; the other caller's 60 is excluded |
| `other -> ... -> ttl#return`, with truth evaluator | 60 |
| Same paths without truth evaluation | Structural candidates 30 and 60 remain |
| `bridge -> ttl#return` | Both outer contexts remain available |
| `page -> ttl#return` when an intermediate call is required | Empty; direct and ancestor paths are distinct |
| Unrelated caller to constant-returning function | Path query is empty; post-filter retains unconstrained 7 |
| Call behind `if (false)` | Present structurally, excluded with truth evaluation |
| Unknown dynamic dispatch | Partial `UNRESOLVED_DISPATCH`; no unrestricted fallback |
| Two calls with arguments `(a,b)` and `(c,d)` | `ab` and `cd`; no invented `ad` or `cb` |
| `Endpoint::show -> CacheQuery::execute#return` | `[30, "product"]`, excluding preview's `[60, "draft"]` |
| Case changes and leading namespace slash | Same selected method result |
| Repeated selected queries on one prepared source | Stable query identity and selected values |

The [benchmark](deriver-call-path-benchmark.php.example) prepares one source
snapshot containing `page() -> value(30)` and unrelated callers of
`value(expensive())`. A `MicroEvaluator` counts actual evaluations of
`expensive()` and returns 999. It is not a timing-only inference.
Preparation is excluded from query timing. Each mode has three warmups and ten
samples in alternating order.

| Unrelated callers | Full query: ms / evaluations | Post-filter: ms / evaluations | Path query: ms / evaluations |
|---|---|---|---|
| 16 | 1.816 / 16 | 1.816 / 16 | 0.203 / 0 |
| 100 | 12.585 / 100 | 12.502 / 100 | 0.358 / 0 |
| 200 | 28.631 / 200 | 29.289 / 200 | 0.433 / 0 |

At 16 callers the full query returns 999 and 30; both selection methods return
30. At 100 and 200 callers the unrestricted query and post-filter retain
`ENUMERATION_LIMIT` partials, while the path query returns analyzed 30. This is
a precision benefit as well as avoided work. It is not a promise of the same
speedup in Magix. `interrupted` is false in these enumeration-limit examples:
consumers must inspect candidate status and partial reasons as well as that
flag. The prototype rejects any partial result.

## Candidate behavior and previous feedback

The prior C1 continuation-loss and C2 throw inconsistencies no longer reproduce:

- `try { $ttl = 30; } finally {} return $ttl;` retains 30.
- The analogous try/catch retains structural candidates 30 and 60. The catch
  candidate is acceptable under the source-candidate contract.
- A throw-only function produces a `NoValue` partial with
  `NO_VALUE_DEFINITION`, not an analyzed null.
- Explicit throw statements, throw expressions and arithmetic exceptions in
  the tested catch regions all retain the catch return 60.

Unknown parameters, supplied inputs with defaults, request data and environment
reads retain `EXTERNAL_INPUT` dependencies. A partial array keeps its known head
and tail. One branch selecting two fields yields `[30, "a"]` or `[60, "b"]`;
subtracting a selected value from its alias yields zero. Unknown conditions are
not pruned just because another condition candidate is known.

A finite summation loop now retains `LOOP_DEPENDENCY`, consistent with the
documented structural contract. The truth evaluator selects zero-iteration and
one-iteration examples correctly. The two-iteration and 10,000-iteration
fixed-assignment examples both retain 11 and 27 and construct the same number
of nodes. This is bounded candidate discovery, not final-value execution.
A recursive countdown retains an enumeration partial by default and produces
8 with truth evaluation. These probes do not prove complete handling of all
PHP constructs, recursive call graphs or real applications.

## Magix migration experiment and equivalence

The [migration patch](deriver-afc9851-migration.patch) adapts only
`ExpressionDeriver` and the Term import in its test. The experiment:

- registers `BranchEvaluators::truth()` so generated alias-budget conditions
  preserve Magix's characterized scope and expansion limit;
- supplies captured inputs through lazy evaluators and a generated caller;
- selects `__magix_entry -> resolve#return`;
- accepts only one analyzed candidate with no collection interruption;
- preserves native null separately from the unresolved sentinel.

The public preparation API currently accepts local files. The old in-memory
source and explicit entry-input facilities are gone, so the prototype writes
and removes a temporary source file per generated snapshot. It does not execute
application code. This is a correctness experiment, not a production-hardened
filesystem adapter: its failure contract, PHPDoc, lint and documentation still
need review before promotion. It is not present in the active source tree.

With the API-only overlay:

- All **33 frozen JSON/Tree/Mermaid reports** match without regenerating them.
- The 61 literal cases and alias identity/scope/cycle/budget checks pass.
- **3,072 numeric combinations** match the original `688356c3` reader.
- All **3,497 tests / 22,874 assertions** pass (sequential PHPUnit).
- Full Analyze JSON for 8,192 tags and the valid 8,192-entry negative-key
  Strategy map matches the original reader, including all entries.

No new output regression was found in this characterized replacement domain.
This claim does not extend to replacing Magix's metadata/control-flow analysis
or using arbitrary application bodies as concrete singleton expressions.

## Magix performance

The implementations measured in the same run are original Magix `688356c3`,
the current `e59f993` integration and the `afc9851` API migration prototype.
Xdebug and CLI opcache are disabled, with a 1 GiB process limit. Three serial,
alternating rounds use three warmups and 20 samples per fixture per round.
The medians below exclude PHP startup; every output hash agrees.

| Analyze fixture | Original ms | Current integration ms | Latest prototype ms |
|---|---|---|---|
| Controller | 25.137 | 24.889 | 36.310 |
| Functional composition | 9.403 | 8.966 | 13.639 |
| Expiration | 13.611 | 12.740 | 13.658 |
| Async composition | 7.243 | 8.779 | 10.792 |

A scan of 200 cached declarations selecting one entry, including process
startup, has three-run medians of **234 / 409 / 909 ms** respectively. All output
hashes match. These short runs include host variability; the raw samples are
retained and do not establish a general performance bound.

Array evaluation with one warmup and one measured sample also remains correct:

| Elements | Original ms | Current integration ms | Latest prototype ms |
|---|---|---|---|
| 128 | 0.016 | 2.985 | 6.329 |
| 1,024 | 0.091 | 20.812 | 40.484 |
| 4,096 | 0.300 | 79.141 | 165.040 |
| 8,192 | 0.575 | 178.961 | 469.213 |

The prototype is slower in this integration shape. Costs include new source
preparation, generated wrappers, temporary files and evaluator setup; these
measurements do not isolate the engine algorithm. The current adapter already
generates a small isolated source world, so it has no large unrelated caller
population for the new pruning feature to remove. Reusing prepared project
sources or batching expressions is a possible future design, not a measured
optimization in this refactor. A supported in-memory preparation/input API
would make this use case easier to implement without per-expression files.

## Reproduction and handoff

The `.php.example` suffix marks standalone examples requiring the new API;
they must not be analyzed as source against Magix's retained old lockfile.
PHP can execute them directly. From the repository root:

```sh
evaluation_dir=$(mktemp -d)
composer --working-dir="$evaluation_dir" require --dev \
  'k-kinzal/deriver:dev-main#afc98516f758f78c6c43d96e084c040d684b3fb3' \
  'nikic/php-parser:5.9.0'
php -d xdebug.mode=off \
  packages/magix-cache-cli/docs/deriver-pruning-reproducer.php.example \
  "$evaluation_dir/vendor/autoload.php" > "$evaluation_dir/probes.json"
python3 packages/magix-cache-cli/docs/deriver-pruning-checks.py \
  "$evaluation_dir/probes.json"
php -d xdebug.mode=off \
  packages/magix-cache-cli/docs/deriver-call-path-benchmark.php.example \
  "$evaluation_dir/vendor/autoload.php" 16
```

Composer still reads branch metadata for a commit-pinned dev requirement. For
future reproduction after main changes, use a checkout of the exact revision
as a Composer path repository, or the archived standalone lockfile.

For the API-only experiment, apply the migration patch in a disposable Magix
worktree with its existing dependencies installed. Prepend a file that requires
that worktree's `vendor/autoload.php` and calls
`$loader->addPsr4('Deriver\\', '/isolated/vendor/k-kinzal/deriver/src', true)`.
Then run PHPUnit with `-d auto_prepend_file=/path/to/overlay.php`. This is an
explicit dependency-resolution bypass for testing only. The patch itself does
not update Composer requirements or the lockfile.

Full raw probe graphs, benchmark inputs, commands, original readers, overlays,
standalone dependency lock and test logs are retained locally in ignored
`build/deriver-evaluation/afc9851.tar.gz`. Committed examples and JSON summaries
provide the shareable feedback; no upstream issue was submitted automatically.

After restoring production source, `composer config:validate`,
`composer packages:validate`, `composer security:audit`, `composer lint` and
`composer test` pass with the retained `e59f993` integration. The final suite has
3,497 tests and 22,874 assertions. The audit required one separate lock update:
`symfony/http-foundation` 7.4.18 to 7.4.21, addressing
[CVE-2026-108082](https://symfony.com/blog/cve-2026-108082-redos-in-headerutils-split-reachable-through-the-cache-control-request-header).
Deriver, Console and PHP-Parser were not changed in the production lock.

Upstream priorities are (1) an installable supported Console range, (2) a
documented efficient path for generated in-memory expressions and explicit
inputs, and (3) preserving the tested caller-path, partial-result and correlation
contracts. A broad static-analysis guarantee remains outside what these tests
can establish.
