# Deriver e59f993 candidate-contract evaluation

Evaluated on 2026-10-05. The lockfile now pins
`e59f993c3d84d23066772b85e1226a66a2d16ae6`, replacing `5996928`.
Only Deriver changed in the lockfile. PHP-Parser remains 5.9.0 and the host is
PHP 8.5.8, with Deriver targeting PHP 8.3 semantics.

## Decision

**Usable for the current Magix declaration-value and recipe-alias replacement.
The previously observed replacement regressions are resolved. Broader static
PHP candidate analysis still has reproducible exception-region gaps.**

The formerly failing 8,192-element negative-key array now resolves. A valid
`array<int, int>` Strategy argument retains all entries, and its complete
Analyze JSON is identical to the original reader. The same holds for the
8,192-tag fixture. No resource budgets were raised and no native fallback or
key-spelling workaround was added.

The new algorithm's source-candidate contract is the basis of this assessment.
We do not require a proof that a candidate is reachable at runtime. A dynamic
condition selecting 30 or 60 should retain those candidates; an unknown input
inside an expression should remain an identifiable dependency. A bounded
analysis stopping early should report that separately. The identified gaps
concern missing source candidates and inconsistent exception representation,
not a request to return to execution simulation.

## Reviewed changes and integration

The [upstream comparison](https://github.com/k-kinzal/deriver/compare/5996928a3757e13ccc83250d4048f4ed59e18dc3...e59f993c3d84d23066772b85e1226a66a2d16ae6)
includes the practical feedback fixes, the candidate engine, and dependency
contract fixes. The [current README](https://github.com/k-kinzal/deriver/blob/e59f993c3d84d23066772b85e1226a66a2d16ae6/README.md)
and [dependency guarantees](https://github.com/k-kinzal/deriver/blob/e59f993c3d84d23066772b85e1226a66a2d16ae6/docs/candidate-dependencies.md)
describe the intended contract.

The default engine follows selected definitions, callers and property writes
backwards. It retains choice guards and residual references in `candidateGraph`,
shares bounded dependency evaluations within a session, and reports
`contract: candidates`, `coverage: source-candidates`, and
`reachability: not-assessed`. Array construction now handles general ordered
key/value operands without retaining every intermediate array, including signed
keys and nonliteral key expressions.

Simply updating the dependency makes the old Magix assessment filter reject
valid values, because it required execution-style `over-approximation` coverage.
`ExpressionDeriver` now explicitly requires the candidate contract, closed exact
and correlated finite enumeration, then uses `definite()` to select a complete
singleton concrete candidate. Multiple candidates, symbolic values, frontiers,
project diagnostics and reported exceptions remain unresolved to Magix. A
literal null stays distinguishable from rejection.

This is consumer-side selection from Deriver's candidates. Magix does not use
`forExecution()`, flatten multiple candidates into one value, infer supplied
user parameters from defaults, or treat a known part as a whole known value.
Generated source contains admitted arithmetic/array expressions with explicit
inputs or bounded functions returning opaque recipe-node indices. Magix keeps
syntax admission, source constant lookup, enum identity, control-flow metadata
analysis, policy composition, and its existing public output domain.

An old adapter test asked an unrelated `unknown();` call to invalidate a later
literal return. That expectation belongs to execution analysis and now asserts
the candidate 30 instead. Exceptional *return expressions* are still rejected.
The former standalone-throw case is preserved in the feedback reproducer rather
than silently accepting its changed result as proof of correctness.

## Candidate and dynamic-input checks

The committed [candidate reproducer](deriver-candidate-reproducer.php) records
32 source cases with raw candidates, assessment, graph, frontiers and
`definite()` status. Successful checks include:

| Case | Observed result |
|---|---|
| Static helper, finite loop, concrete recursion | Concrete 35, 45 and 8 respectively |
| Unknown boolean selecting 30 or 60 | Both candidates retained; not definite |
| Both branches returning 30 | Singleton 30 |
| Unknown integer added to 5 | Binary expression retains 5 and a named `EXTERNAL_INPUT` reference |
| Unknown middle array element | Known `head` and `tail` survive around the input reference |
| Unknown parameter with default 30 | Source query retains the unknown input |
| Request input / `getenv()` | External dependency remains explicit |
| Known callers supplying 30 and 60 | Return candidates 35 and 65 |
| Property initializer and ordinary setter | Both `users` and `archive` source candidates |
| Two array fields selected by one branch | Nested choices retain the same guard identity |
| Selected value minus its alias | Singleton 0; no invented cross-product |
| Known alternative beside a dynamic expression | 30 and the partial `5 + input` both retained |
| Depth limit / enumeration limit | Open residual graph with the respective stopping reasons |

Nested array choices remain symbolic rather than becoming an eagerly enumerated
list of concrete arrays. Their guards are present in the graph. Consumers must
inspect the graph or use the API's concrete-value checks, not equate one
`normalOutcomes` entry with one concrete candidate.

## Remaining upstream feedback

### C1: A static return after an exception region disappears

```php
function resolve() {
    try { $ttl = 30; } finally {}
    return $ttl;
}
```

`ReturnQuery('resolve')` produces an empty candidate graph and zero normal or
exceptional outcomes. Its assessment is nevertheless closed, exact-symbolic,
and finite-exhaustive, with no frontiers or project diagnostics. The source
contains a constant 30 definition feeding the requested return. There is no
external input, recursive dependency or exhausted budget to explain its loss.
A `try/catch` followed by the same return also loses all candidates. Running
these small, authored fixtures independently in PHP returns 30.

Source inspection suggests the boundary: `Graph::returns()` traverses only
terminator targets, whereas `ExceptionLowering` emits `leave-try` / `resume`
and keeps continuation destinations in exception-region metadata. Candidate
traversal needs to retain those continuation dependencies, or report a residual
when it cannot. The result must not silently present a missing dependency as
an empty complete candidate set.

The same source and public query on the previous `5996928` default engine return
30. Although the two engines have different contracts, 30 is also a source
candidate here; restoring it does not require runtime reachability analysis.

### C2: Standalone throws and throw expressions disagree

```php
function resolve() { throw new RuntimeException(); }
```

This reports a singleton concrete null, no exceptional outcomes, and a non-null
`definite()` result. Likewise, a standalone throw in one arm of an `if` is absent
from the exceptional outcomes, while an equivalent throw expression used by a
ternary is represented. A `return throw new RuntimeException()` inside a catchable
region also differs from arithmetic failure handling: the tested RuntimeException
catch candidate 60 is absent, while catching `1 / 0` correctly yields 60.

The candidate engine can intentionally ignore unrelated calls without proving
their execution. This report does not request the opposite. It asks for a
consistent documented representation of explicit throw dependencies, and for
the throw-only case not to manufacture an unqualified normal null. The raw
results preserve the exact syntax variants for upstream review.

For comparison, `5996928` reports one exceptional outcome and no normal value
for the throw-only function, and candidate 60 for the explicit caught throw.

Neither exception-region form is emitted by the current Magix value/alias
adapter. Therefore these findings do not reproduce as a regression in this
refactor's admitted inputs. They are a reason to avoid expanding the integration
to arbitrary method bodies without further characterization and fixes.

## Performance

Measurements disable Xdebug and CLI opcache, with a 1 GiB PHP process limit and
unchanged Deriver budgets. The three implementations are original Magix
`688356c3`, previous integration `5996928`, and current candidate integration
`e59f993`. The previous adapter is preloaded together with the previous Deriver
source, so its execution assessment filter is preserved.

Three serial alternating rounds, three warmups and 20 samples per fixture per
round; medians of 60 samples, excluding PHP process startup:

| Analyze fixture | Original Magix | Previous integration | Current integration |
|---|---:|---:|---:|
| Project controller | 12.165 ms | 13.959 ms | 13.915 ms |
| Functional composition | 4.052 ms | 4.925 ms | 4.737 ms |
| Expiration | 6.811 ms | 6.689 ms | 6.807 ms |
| Async composition | 3.885 ms | 4.378 ms | 4.209 ms |

All output hashes match across all implementations and samples. A separate
200-declaration scan selecting one entry has matching output and whole-process
medians of 116.297 ms, 170.734 ms and 165.853 ms respectively, across three
alternating runs per mode. These are usable development-tool latencies for the
measured workload; small differences between dependency revisions are not a
general speedup claim.

One warm array sample per size:

| Elements | Original Magix | Previous integration | Current integration |
|---|---:|---:|---:|
| 128 | 0.007 ms | 1.643 ms | 1.359 ms |
| 1,024 | 0.040 ms | 8.624 ms | 8.370 ms |
| 4,096 | 0.161 ms | 34.300 ms | 33.706 ms |
| 8,192 | 0.328 ms | 74.441 ms | 73.765 ms |

The original direct array reader remains much faster. The important improvement
over the previous integration is recovered signed-key behavior: an 8,192-entry
array beginning with `-1 => 0` now matches the original in about 66 ms in the
integrated shape probe, instead of reaching the old memory frontier. String
keys, positive keys, the equivalent `'-1'` key, and nested values also match.
Raw Deriver probes succeed for `-1`, `'-1'`, and `-(1 + 0)` at that size.
Do not compare these absolute timings directly with earlier evaluation dates;
host load differs, and array shape timings are single observations.

## Validation and reproduction

- All 33 frozen JSON/Tree/Mermaid reports and 61 literal cases are unchanged.
- All 3,072 numeric differential combinations match, comparing NaN as NaN.
- Alias scope, leaf identity, cycles and expansion budgets are unchanged.
- Three large-array invariance tests pass against both the original reader and
  this integration, including the newly protected negative-key case.
- Full suite: **3,497 tests / 22,874 assertions**, all passed.
- Configuration validation, package validation, security audit and full lint pass.
- `composer doc-gen` passes, including strict coverage. The generated adapter
  documentation states the candidate contract and explicit-input requirement.

```sh
php -d xdebug.mode=off -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-candidate-reproducer.php
php -d xdebug.mode=off -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-reproducer.php 8192 negative-key
php -d xdebug.mode=off -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-reproducer.php 8192 negative-expression-key
```

The current standalone runs have empty stderr. The differential oracle itself
uses the original reader, whose PHP 8.5 power deprecations are captured separately.
The [original-reader benchmark setup](deriver-feedback.md#reproduction) and
[Strategy-map fixture generator](deriver-evaluation-5996928.md#reproduction)
remain applicable. [Machine-readable evidence](deriver-evaluation-e59f993.json)
records revisions, samples, output hashes, recovered values and candidate
feedback. No upstream issue has been submitted automatically.
