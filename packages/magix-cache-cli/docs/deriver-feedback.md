# Deriver integration and upstream feedback

## Revisions and baseline

The package matching the requested value-expansion mechanism is
[`k-kinzal/deriver`](https://github.com/k-kinzal/deriver). Its source repository is
[`ztd-query-php/packages/deriver`](https://github.com/k-kinzal/ztd-query-php/tree/main/packages/deriver).
This integration was evaluated with Deriver
`e2773a13fbe3d58100240e455dc045785fa78f50`, PHP-Parser 5.9.0, and host PHP 8.5.8.
Deriver's target profile is PHP 8.3, 64-bit. The root lockfile records the exact
dependency revisions; the upstream package currently has only `dev-main`.

The original Magix implementation is `688356c3`. Before replacing production
code, the refactor captured 33 complete Analyze reports and 61 literal-expression
cases in commit `fb74ac1e`. Commit `6d47e351` separately records recipe alias
identity, scope, cycles and the eight-step expansion limit before replacing that
resolver. The original suite passed with 3,387 tests and 22,551 assertions.

The report expectations have not been regenerated during the migration. They
compare JSON diagnostics, alternatives and certainty as well as Tree ANSI colors
and Mermaid output. See [the baseline manifest](../tests/Invariance/Baseline/README.md).

## Integration boundary

`ExpressionDeriver` uses only Deriver's public `Analyzer`, source snapshot, query,
entrypoint and value APIs. It evaluates isolated generated source with explicitly
captured inputs. It accepts one concrete normal outcome only when analysis is
exact and closed, has over-approximate coverage, and contains no frontiers,
project diagnostics or exceptional outcomes. Symbolic values, interrupted work
and a concrete result on only one possible path cannot become policy facts.

`LiteralReader` delegates unary and binary evaluation and array key ordering and
collisions to this boundary. It retains the existing admitted declaration value
domain, source constant lookup, cycle detection and trusted library enum lookup.
Array entries use opaque value indices so enums keep their identity without
passing executable objects into Deriver. The adapter does not retry using the
old evaluator when Deriver cannot answer.

`BindingDeriver` translates the existing recipe binding map into an isolated
graph of functions returning source-node indices. Deriver expands the aliases;
the selected original node retains identity and source positions. Factories and
constructors remain opaque leaves. The map's established scope and expansion
budget are preserved, independently of PHP assignment execution order.

Magix's metadata operations, dependency graph, policy priority and field certainty
remain its own abstract domain. Deriver does not evaluate cache callbacks or
replace metadata bubbling with ordinary PHP values. Application files and their
autoloaders are never executed by these adapters.

## Reproduce observations without Magix's adapters

From this repository root after `composer install`:

```sh
php -d xdebug.mode=off -d display_errors=stderr \
  packages/magix-cache-cli/docs/deriver-reproducer.php \
  > /tmp/deriver-feedback.json 2> /tmp/deriver-feedback.stderr
```

The standalone script calls Deriver directly and includes the exact source,
dependency revisions, host settings and full raw Deriver result for every case.
Keep stderr: the host-deprecation case intentionally exposes the warning instead
of suppressing it. Attach both files to upstream feedback. No issue is submitted
automatically.

## D1: Float-to-string precision cannot be supplied through TargetProfile

Source: `function resolve() { return 'v' . 0.25; }`

Magix previously returned `v0.25`. Deriver returns an opaque value with an open
assessment and the `FLOAT_STRING_CONFIGURATION` frontier. This is a documented
conservative limitation, not an incorrect numeric result. `TargetProfile`
currently accepts only the language version and integer width; there is no
precision parameter for reproducing the CLI's captured host conversion.

The integration explicitly converts admitted scalar concatenation operands to
strings before querying Deriver. This preserves the CLI's established host
conversion, including its precision setting. Deriver performs concatenation.
No application expression is executed to obtain that conversion.

Suggested upstream capability: an explicit, captured float-to-string profile
that participates in the snapshot identity. A precision-sensitive case such as
`'v' . (1 / 3)` should be included when validating that API.

## D2: Host PHP 8.5 deprecation escapes the PHP 8.3 target

Source: `function resolve() { return 0 ** -1; }`

Deriver produces the expected `INF`, but its internal host exponentiation emits
`E_DEPRECATED` on PHP 8.5: “Power of base 0 and negative exponent is deprecated”.
The target remains PHP 8.3. This warning is not represented as a result frontier
and can interfere with hosts that convert diagnostics to exceptions.

The old Magix evaluator also emitted this host warning, so this refactor does
not introduce a numerical regression. The integration does not suppress host
diagnostics. Suggested upstream fix: implement target-specific arithmetic
without leaking diagnostics from a newer host runtime.

## Consumer semantics to preserve, not upstream defects

| Source | Original Magix result | Raw Deriver result | Integration |
|---|---|---|---|
| `[null => 'a']` | `[0 => 'a']` | `['' => 'a']`, matching PHP | Normalize null keys to append before deriving array structure. Changing the existing Magix interpretation belongs in a separate behavior change. |
| `'60' + 5` | Unresolved | `65`, matching PHP | Keep the reader's numeric operand admission rules. |
| `$missing` without a binding | Unresolved | Concrete null, closed assessment, `PHP_WARNING` frontier | Reject frontier-bearing results; checking only `isConcrete()` and `closure` is insufficient. |
| `Visibility::Private` without its source | The trusted library enum case | `INCOMPLETE_SOURCE` | Capture trusted enum identity at the source boundary; never execute an application autoloader. |

Deriver also resolves operators that Magix's reader intentionally leaves unknown,
including boolean expressions, ternaries and array unpacking. The refactor keeps
that analysis domain unchanged. Broader support needs its own behavior tests and
review rather than altered baseline files.

## Additional differential experiment

A direct comparison against the original `LiteralReader` evaluated 3,072 binary
expressions: 12 operators over every pair of 16 numeric operands, including zero,
negative values, integer limits, fractional values and very large floats.
All returned values agreed (with NaN compared as NaN). Both implementations
emitted the deprecation described in D2 for zero raised to a negative power.
This exploratory matrix supplements the committed regression suite; it is not a
claim that every PHP expression or every future Deriver revision is equivalent.
