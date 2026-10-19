# Analyze value-resolution baseline

These reports were captured from `688356c3` before changing production code or
adding a resolver dependency. `AnalyzeBaselineTest::reports()` is the manifest:
it records the source paths, selected entry points, formats, display filters and
ANSI decoration used to produce each file.

The 33 reports preserve complete output, rather than selected TTL labels or
hashes. JSON retains declarations, execution facts, alternatives, uncertainty,
diagnostics, strategy steps, source locations and storage judgments. Tree
snapshots also preserve ANSI colors; Mermaid preserves labels and styles.
The corpus covers policies, strategies, daily expiration, alternative TTLs,
functional and asynchronous composition, invocation parameters, incomplete
analysis and display filtering.

`LiteralBaselineTest` separately records the existing constant-expression value
domain. Both resolved values and unresolved expressions are part of the
refactoring baseline. Existing reader, graph and invariance tests cover local
bindings, constant catalogs, recursion, exclusive branches and metadata laws.

Run the comparisons from the repository root:

```sh
vendor/bin/phpunit packages/magix-cache-cli/tests/Invariance/AnalyzeBaselineTest.php
vendor/bin/phpunit packages/magix-cache-cli/tests/Invariance/LiteralBaselineTest.php
composer test
```

Tests never update these files. Do not regenerate them to accept a resolver
regression. Investigate a difference against the original revision and record
the source, expected result, actual result and resolver revision in an upstream
reproducer first. An intentional later behavior change requires a separate
review of the corresponding expectations.

To independently reproduce a snapshot, use the manifest's exact options with
`CommandTester` and `Application` at the baseline revision. Pass the recorded
`decorated` option to `execute()` and compare `getDisplay()` byte for byte. Use
the repository root as `Application`'s working directory so source paths remain
relative; no paths, diagnostics or numeric fields are stripped from the reports.
