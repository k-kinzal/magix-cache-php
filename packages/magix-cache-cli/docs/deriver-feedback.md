# Deriver integration and upstream feedback

## Current assessment (2026-10-02)

Updated [`k-kinzal/deriver`](https://github.com/k-kinzal/deriver) from
`e2773a13fbe3d58100240e455dc045785fa78f50` to
`0d1cb9e0fdee7575bd79d008bc8f9b23ee3e3d35` and applied its new public APIs.
The source repository is
[`ztd-query-php/packages/deriver`](https://github.com/k-kinzal/ztd-query-php/tree/main/packages/deriver).
PHP-Parser remains 5.9.0; the evaluation host is PHP 8.5.8. Deriver targets PHP
8.3 on 64-bit systems. Only Deriver changed in the lockfile.

The update fixes the previously reported precision limitation and host
exponentiation deprecation, and supplies the requested definite-result helper.
The frozen Analyze reports and numeric differential matrix still agree with the
original implementation. **This does not establish full behavioral equivalence:**
new stress testing found that an 8,192-element constant array becomes unresolved
where the original reader returns all elements. Large arrays are also much
slower. Both issues reproduce with the previous Deriver revision as well; they
are remaining integration regressions, not newly introduced by this update.
The PR should not be merged as a behavior-preserving refactor while this known
loss of analysis remains.

## Baseline and integration boundary

The original Magix implementation is `688356c3`. Before replacing production
code, commit `fb74ac1e` captured 33 complete Analyze reports and 61 literal cases.
Commit `6d47e351` separately characterized recipe alias identity, scope, cycles
and the eight-step expansion limit. The original suite passed with 3,387 tests
and 22,551 assertions. The report expectations have never been regenerated;
they cover JSON diagnostics, alternatives and certainty, Tree ANSI colors, and
Mermaid. See [the baseline manifest](../tests/Invariance/Baseline/README.md).

`ExpressionDeriver` evaluates isolated generated source with captured inputs.
It requires the existing exact/closed assessment and uses `definite()` to reject
multiple, symbolic, exceptional, frontier-bearing or diagnostic-bearing results.
The host's `precision` directive is now captured in `TargetProfile` for each
snapshot. `LiteralReader` passes the original numeric operands to Deriver;
the previous local float-to-string conversion workaround has been removed.

`LiteralReader` still owns syntax admission, source constant lookup, cycle
detection and trusted library enum identity. Deriver evaluates admitted unary
and binary operations and array keys/order/collisions. Array values use opaque
indices so executable objects never enter Deriver. `BindingDeriver` translates
the existing recipe binding map into functions returning original-node indices,
preserving source locations and the existing alias scope and expansion budget.
There is no fallback to the old evaluator.

This is still a limited integration: Magix retains its control-flow readers,
variable metadata tracking, dependency graph, composition and policy analysis.
The update does not replace those algorithms or expand the supported expression
domain. Application factories, callbacks, constructors and autoloaders are not
executed. Creating a generated source snapshot for small operations remains an
integration cost; improvements to Deriver alone do not remove that cost.

## Fixed feedback

### D1: Captured float precision — fixed and adopted

Previously, `'v' . 0.25` remained opaque with `FLOAT_STRING_CONFIGURATION`, and
`TargetProfile` offered no precision input. The latest revision accepts
`floatPrecision`, includes it in snapshot identity, and derives `v0.25` at
precision 14. `'v' . (1 / 3)` produces `v0.333` at precision 3 and
`v0.33333333333333331` at precision 17. An unspecified precision correctly
remains unresolved.

The adapter captures the CLI's current host setting on each query. A regression
test changes precision from 3 to 17 and back on the same reader, checks each
result, and checks that derivation leaves the host setting unchanged. An
additional comparison of seven float operands at seven precision settings
(-1, 0, 1, 2, 3, 14, 17) found no value differences from the original reader.

### D2: Host exponentiation diagnostic — fixed

`0 ** -1` now derives `INF` without leaking PHP 8.5's host deprecation.
`(-0.0) ** -3` preserves the negative infinity sign. The standalone reproducer
has empty stderr on the evaluation host, and the strict test suite covers both
results without suppressing diagnostics. The original Magix evaluator and the
previous Deriver revision emitted the host deprecation.

### Definite-result API — adopted

`DerivationResult::definite()` supplies the requested consumer acceptance
helper. The adapter now uses it, retaining its existing assessment requirements.
An unbound `$missing` has a concrete null and closed assessment but a
`PHP_WARNING` frontier, so `definite()` rejects it; a literal `null` is accepted.
Tests continue to reject symbolic branches, exceptional alternatives and opaque
calls. This removes duplicated outcome validation without widening what Magix
accepts as a declaration fact.

## Remaining feedback

### D3: Large literal arrays have substantial evaluation overhead

For an isolated `return [0, 1, ..., 4095];`, the original Magix reader constructs
the array directly, while the integration builds source, lowers and evaluates
it in Deriver, and maps the result back to source values. An exploratory run
measured approximately 0.6 ms in the original reader and 6.5 seconds through the
latest integration. This is an isolated operation, not an entire Analyze run;
see the reproducible benchmark and measured report below for broader context.

The reproduction needs no application models or callbacks. Improving bulk
literal construction and retained intermediate state in Deriver would help;
Magix also needs to reconsider opening source sessions for small value
operations. A source/session reuse design should be evaluated separately rather
than claiming that this dependency update resolves the integration overhead.

### D4: A constant array becomes unknown at the default memory limit

Reproduction source: a function returning the literal array of integers 0
through 8191. The source is about 40 KB. The original `LiteralReader` returns all
8,192 elements; the integrated reader returns `LiteralReader::UNRESOLVED`.

Direct Deriver analysis reaches its default 268,435,456-byte **additional query
memory** limit and reports `MEMORY_LIMIT` frontiers for `runtime-resources`,
`logical-work`, and `summary-fixed-point`. Its assessment is open/opaque and
`definite()` returns null. This was reproduced with a PHP process limit of 1 GiB;
increasing PHP's limit alone does not change Deriver's independent default.
The adapter correctly rejects a partial result rather than inventing a value,
but it loses a value the original implementation could determine.

The previous integration with Deriver `e2773a1` also returns unresolved for the
same input. The earlier 61 literal cases and 3,072 numeric combinations did not
exercise this size. This newly discovered regression supersedes any broader
interpretation of the earlier successful compatibility checks.

Suggested upstream investigation: avoid retaining quadratic intermediate state
when constructing flat literal arrays, and document practical memory scaling.
Intermediate-state retention is a hypothesis suggested by the observed growth,
not a measured allocation profile.
Simply raising the limit is not a verified solution and would leave the latency
problem. This is a merge blocker for strict equivalence, even though default
resource interruption is conservative behavior within Deriver's own contract.

This also affects a complete Analyze command, not only the low-level reader.
A boundary declaring `#[Cache(ttl: 30, tags: ['tag0', ..., 'tag8191'])]` and
returning `$this->cached(static fn (): Cached => Cached::of('value'))` reports
all 8,192 tags with the original reader. The updated integration reports zero
known tags, `tagsUnknown: true`, and partial tag certainty. Its TTL remains 30s
and its storage proof remains `yes`; the lost information is the tag declaration.
The [machine-readable evaluation](deriver-evaluation.json) preserves both
results and the resulting diagnostic.

## Measured command behavior

Three serial, alternating process rounds measured 60 samples per fixture and
implementation. Every output hash matched across the original reader, the
previous integration and the updated integration. Timings in milliseconds:

| Analyze fixture | Original median | Previous integration median | Updated integration median |
|---|---:|---:|---:|
| Project controller | 54.004 | 55.466 | 60.619 |
| Functional composition | 14.709 | 21.659 | 28.912 |
| Expiration | 23.385 | 25.699 | 33.557 |
| Async composition | 11.557 | 19.323 | 19.338 |

The host was shared and variation between rounds was substantial: for example,
original controller round medians ranged from 41.957 to 81.178 ms. These results
do **not** establish a precise performance ratio between Deriver revisions or
show that the update improves speed. The much larger array penalty reproduced
separately: the committed benchmark recorded 0.802 ms versus 8,909.610 ms for
4,096 elements, and 1.276 ms versus 10,469.477 ms for 8,192 elements, with the
latter integrated result unresolved. Array timings are single samples after a
warmup, not statistical estimates. Raw summary measurements, per-round medians,
output hashes and remaining frontiers are in
[deriver-evaluation.json](deriver-evaluation.json).

## Consumer semantics to preserve, not upstream defects

| Source | Original Magix result | Raw Deriver result | Integration |
|---|---|---|---|
| `[null => 'a']` | `[0 => 'a']` | `['' => 'a']`, matching PHP | Normalize null keys to append. Correcting the existing Magix interpretation belongs in a separate behavior change. |
| `'60' + 5` | Unresolved | `65`, matching PHP | Keep the reader's numeric operand admission rules. |
| `$missing` without a binding | Unresolved | Concrete null with a `PHP_WARNING` frontier | Reject via `definite()`. |
| `Visibility::Private` without its source | The trusted library enum case | `INCOMPLETE_SOURCE` | Capture trusted enum identity; never execute an application autoloader. |

Deriver also supports boolean expressions, ternaries and array unpacking that
Magix intentionally leaves unresolved. This refactor does not expand that
domain. Invalid UTF-8 array keys and concatenation operands also matched the
original reader in the additional probes.

## Reproduction

After `composer install`, run the standalone Deriver cases:

```sh
php -d xdebug.mode=off -d display_errors=stderr \
  packages/magix-cache-cli/docs/deriver-reproducer.php \
  > /tmp/deriver-feedback.json 2> /tmp/deriver-feedback.stderr

# Includes the larger array and full raw result/frontiers; allow several seconds.
php -d xdebug.mode=off -d memory_limit=1G -d display_errors=stderr \
  packages/magix-cache-cli/docs/deriver-reproducer.php 8192 \
  > /tmp/deriver-array-feedback.json 2> /tmp/deriver-array-feedback.stderr
```

The JSON includes exact source, dependency revisions, precision, assessment,
frontiers, raw outcomes and `definite()` acceptance. Keep stderr as evidence of
host diagnostic behavior. No upstream issue is submitted automatically.

Compare the original readers with the current integration under identical
installed dependencies and fixtures:

```sh
baseline_dir=$(mktemp -d)
git show 688356c3:packages/magix-cache-cli/src/Reader/LiteralReader.php > "$baseline_dir/LiteralReader.php"
git show 688356c3:packages/magix-cache-cli/src/Reader/StrategyReader.php > "$baseline_dir/StrategyReader.php"

php -d xdebug.mode=off -d opcache.enable_cli=0 -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-benchmark.php "$baseline_dir" \
  > /tmp/magix-original.json
php -d xdebug.mode=off -d opcache.enable_cli=0 -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-benchmark.php \
  > /tmp/magix-integrated.json

# Direct array comparison; each size is measured once after a warmup.
php -d xdebug.mode=off -d opcache.enable_cli=0 -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-benchmark.php "$baseline_dir" --arrays \
  > /tmp/magix-original-arrays.json
php -d xdebug.mode=off -d opcache.enable_cli=0 -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-benchmark.php --arrays \
  > /tmp/magix-integrated-arrays.json
```

The regular benchmark records 20 command timings after three warmups for each
fixture, including application construction and rendering but excluding PHP
process startup. Compare output hashes as well as durations. Run serially and
alternate baseline/integration order across multiple rounds; these small
fixtures are not a representative production workload.

## Validation scope

- The 33 frozen Analyze reports, 61 literal cases and alias characterization
  tests pass without changing expectations.
- All 3,072 binary combinations (12 operators over 16 numeric operands,
  including integer limits and non-finite results) still match the original
  reader. NaN is compared as NaN. Only host diagnostics differ as described above.
- The full suite passes with 3,492 tests and 22,866 assertions, including the new
  precision and signed-infinity tests.
- Configuration, package manifests, security audit, formatting, PHPStan,
  LocGuard, TreeGuard and Deptrac pass. Strict coverage and API generation pass.
- These checks do not include an equivalence assertion for the known failing
  8,192-element array. The standalone scripts expose it explicitly rather than
  changing a test to treat the lost analysis as an acceptable new baseline.
