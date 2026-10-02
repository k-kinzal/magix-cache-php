# Deriver practical evaluation — 2026-10-03

## Decision

The latest Deriver works for the tested normal Magix Analyze workloads, but it
is **not ready to merge as a behavior-preserving replacement of the original
reader**. The previously reported large-array regression remains: 8,192 known
cache tags become unknown. Ordinary command latency is usable in the measured
small and synthetic projects, but is higher than the original reader, and
large arrays still take seconds. The update does not resolve the reason this
PR was made draft.

This assesses this Magix integration and its admitted value domain, not whether
Deriver is suitable for every other static-analysis use case. No universal
equivalence or production capacity claim follows from these tests.

## Reviewed change

Updated only `k-kinzal/deriver` from
`0d1cb9e0fdee7575bd79d008bc8f9b23ee3e3d35` to
`97ad01a77f46cf33986c95314eafe40b63d169b5` in `composer.lock`. PHP-Parser remains
5.9.0. The Magix adapters required no API changes or workarounds.

The [upstream diff](https://github.com/k-kinzal/deriver/compare/0d1cb9e0fdee7575bd79d008bc8f9b23ee3e3d35...97ad01a77f46cf33986c95314eafe40b63d169b5)
includes:

- Bounded path/outcome joining and retained string prefixes, plus a separate
  symbolic-recursion limit.
- Explicit entry receiver properties, property initialization handling, and
  more precise shared/global state handling.
- Array mutation, reference iteration, formatting and goto handling.
- Richer call/declaration metadata, named frontier dependencies, and stack
  interruptions that stop the affected call without permanently stopping the
  entire query.

These broaden Deriver's facilities and improve how partial results are
represented. Much of that functionality is outside the expressions Magix
currently admits; the existing Magix control-flow and metadata readers are
still in place. Consequently, this dependency update does not itself expand
Magix's accepted declaration domain or remove its adapter overhead.

## Correctness and validation

- All 33 frozen JSON/Tree/Mermaid reports, 61 literal cases and alias scope,
  identity, cycle and depth checks pass without regenerating expectations.
- All 3,072 binary combinations match the original `LiteralReader` from
  `688356c3`, including numeric limits and non-finite values.
- The full suite passes: **3,492 tests / 22,866 assertions**.
- `composer config:validate`, `composer packages:validate`,
  `composer security:audit`, `composer lint`, and `composer test` pass.
- Previously fixed precision handling, signed infinity, warning-free
  exponentiation and rejection of warning-bearing concrete null still work.
- The default resource limits were not raised. No old evaluator fallback was
  added, and partial outcomes are still rejected rather than turned into facts.

No new value regression was found in these checks relative to Deriver
`0d1cb9e`. The known regression relative to the original Magix reader remains.

## Timing measurements

Host PHP 8.5.8; Xdebug and CLI opcache disabled; PHP memory limit 1 GiB. Each
normal fixture has 60 timed commands over three serial, alternating process
rounds, after three warmups per round. These timings exclude process startup.
Every output hash matches across all three implementations.

| Analyze fixture | Original reader | Deriver 0d1cb9e | Deriver 97ad01a |
|---|---:|---:|---:|
| Project controller | 24.529 ms | 30.718 ms | 32.888 ms |
| Functional composition | 9.106 ms | 10.494 ms | 12.105 ms |
| Expiration | 13.619 ms | 13.661 ms | 17.040 ms |
| Async composition | 8.405 ms | 9.508 ms | 11.641 ms |

The machine is shared and round-to-round variation exists. These observations
do not establish a precise performance regression between Deriver revisions;
they also provide no evidence that this update reduces the integration cost.

A separate synthetic project contains **200 independent cached methods**, each
with `ttl: 30 + 5` and three literal tags. The command scans them all and selects
`Query0`, producing one root. Three alternating full process runs, including
startup, had medians of **201.986 ms** for the original reader, **304.408 ms**
for the previous integration, and **307.456 ms** for the update. All nine output
hashes match. This is a useful development-command latency in that scenario,
but the roughly 105 ms overhead remains. It is not a benchmark of a deep graph,
framework application, or 200 returned analysis trees.

## The large-array blocker remains

The existing array benchmark was rerun serially with one warm sample per size:

| Elements | Original reader | Deriver 0d1cb9e | Deriver 97ad01a | Latest result |
|---|---:|---:|---:|---|
| 128 | 0.015 ms | 8.078 ms | 10.238 ms | Exact |
| 1,024 | 0.080 ms | 230.926 ms | 248.855 ms | Exact |
| 4,096 | 0.328 ms | 3,599.226 ms | 3,343.585 ms | Exact |
| 8,192 | 0.766 ms | 4,581.274 ms | 3,709.056 ms | Unresolved |

These are single samples, so the differences between Deriver versions are not
speedup estimates. The order-of-magnitude cost and loss of a concrete result
remain clear. Do not compare these numbers directly with the previous day's
measurements on a differently loaded host.

The direct Deriver reproducer still reaches its default **256 MiB additional
query memory** limit on `return [0, 1, ..., 8191];`, with `MEMORY_LIMIT` frontiers
and an open/opaque assessment. `definite()` returns null. A separate complete
Analyze run on `#[Cache(ttl: 30, tags: ['tag0', ..., 'tag8191'])]` confirms the
user-facing effect:

| Field | Original reader | Latest integration |
|---|---|---|
| Known effective tags | 8,192 | 0 |
| `tagsUnknown` | `false` | `true` |
| TTL | 30s | 30s |
| Storage proof | yes | yes |

This does not invent an incorrect numeric TTL or claim unknown tags are known.
It loses analysis that previously succeeded, violating this refactor's contract.

## Focused upstream feedback

The flat literal construction path still lowers each element into a separate
`array-set`. `Arrays::set()` copies and updates the prior operand array, while
`InstructionTransfer` retains each result in the state's registers. This is a
concrete area to investigate for the growing retained intermediate arrays;
we have not collected an allocation profile assigning all memory to one site.

Relevant source at the evaluated revision:

- [AggregateLowering](https://github.com/k-kinzal/deriver/blob/97ad01a77f46cf33986c95314eafe40b63d169b5/src/Source/Compilation/AggregateLowering.php)
- [Arrays::set](https://github.com/k-kinzal/deriver/blob/97ad01a77f46cf33986c95314eafe40b63d169b5/src/Value/Arrays.php)
- [InstructionTransfer](https://github.com/k-kinzal/deriver/blob/97ad01a77f46cf33986c95314eafe40b63d169b5/src/Evaluation/InstructionTransfer.php)

The next useful change for this integration is efficient large literal-array
construction and bounded retention of intermediate values, while preserving
query observations, key collisions and source ordering. Raising memory limits
alone would not address latency. Magix's repeated small source snapshots remain
a separate integration design concern.

## Reproduction and evidence

The [existing reproducer and benchmark instructions](deriver-feedback.md#reproduction)
still apply unchanged. The raw Deriver script accepts `8192` to reproduce the
memory frontier; the comparison benchmark accepts `--arrays`. Full sources,
frontiers and diagnostics are emitted rather than automatically submitted as
an upstream issue.

[Machine-readable results](deriver-evaluation-2026-10-03.json) preserve the
revisions, medians, per-round values, output hashes, complete tag diagnostic,
resource frontiers and validation counts. Earlier reports are retained.

To recreate the 200-boundary scan fixture:

```sh
mkdir -p /tmp/deriver-project-200
python3 - <<'PY'
from pathlib import Path
source = '''<?php
namespace DeriverFeedback;
use Magix\\Cache\\Attribute\\Cache;
use Magix\\Cache\\Cacheable;
use Magix\\Cache\\Cached;
'''
for i in range(200):
    source += f'''final class Query{i} {{
    use Cacheable;
    #[Cache(ttl: 30 + 5, tags: ['product', 'catalog', 'query{i}'])]
    public function execute(): Cached {{
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }}
}}
'''
Path('/tmp/deriver-project-200/Queries.php').write_text(source)
PY
php -d xdebug.mode=off -d opcache.enable_cli=0 -d memory_limit=1G \
  packages/magix-cache-cli/bin/magix analyze 'DeriverFeedback\Query0' \
  --path=/tmp/deriver-project-200 --format=json --uncached=all
```

For the original-reader comparison, preload the two `688356c3` readers as the
existing benchmark does. This preserves the current dependency versions and
all other Magix code so the comparison isolates value resolution.
