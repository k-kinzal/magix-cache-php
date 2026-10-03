# Deriver 5996928 practical evaluation

Evaluated on 2026-10-03 against the original Magix reader (`688356c3`) and the
previous integration using Deriver `97ad01a`. The latest revision is
`5996928a3757e13ccc83250d4048f4ed59e18dc3`; PHP-Parser remains 5.9.0 and the host
is PHP 8.5.8.

## Decision

**Substantial improvement; ordinary tested workloads and plain large arrays
are usable. Strict replacement equivalence still has a narrower blocker.**

The original 8,192-element list and 8,192-cache-tag failures are fixed without
raising budgets or adding a Magix fallback. Full Analyze output for the tag
fixture matches the original reader. Large-array cost is now approximately
linear over the measured plain sizes, and has fallen from seconds to tens or
hundreds of milliseconds.

However, inserting one negative integer key into a large array bypasses the
new optimization and still exhausts the default additional query memory
limit. The original reader resolves it. This is remaining old behavior in an
unoptimized shape, not a newly introduced wrong value. The PR remains draft
because the requested refactor must retain previously available analysis.

## Reviewed upstream changes

The [upstream diff](https://github.com/k-kinzal/deriver/compare/97ad01a77f46cf33986c95314eafe40b63d169b5...5996928a3757e13ccc83250d4048f4ed59e18dc3)
adds `LiteralArrayLowering`: eligible scalar arrays are built once instead of
retaining every incrementally constructed array. Scalar source observations
are retained. This directly addresses the earlier large-array bottleneck.

Other changes include bounded reuse of closed isolated function summaries,
partial observations after interrupted execution, more resource checks, closure
captures, symbolic entry arguments, and richer call/comment metadata. Summary
reuse is within a session; Magix still opens generated-source sessions, so it
does not automatically gain every reuse benefit. No adapter API changes were
required for this update, and the admitted Magix expression domain is unchanged.

## Measurements

All measurements disable Xdebug and CLI opcache and use a 1 GiB PHP process
limit, leaving Deriver's own defaults unchanged. Original readers and previous
Deriver sources are preloaded into otherwise identical code and dependencies.

### Plain arrays

One sample per size after a warmup, in serial processes:

| Elements | Original Magix | Deriver 97ad01a | Deriver 5996928 | Latest result |
|---|---:|---:|---:|---|
| 128 | 0.013 ms | 7.034 ms | 2.686 ms | Exact |
| 1,024 | 0.068 ms | 195.340 ms | 17.515 ms | Exact |
| 4,096 | 0.285 ms | 3,991.018 ms | 70.522 ms | Exact |
| 8,192 | 0.600 ms | 3,676.785 ms | 141.816 ms | Exact; previously unresolved |

The isolated array operation remains slower than the original direct reader.
Nevertheless, the observed reduction is large and the lost plain-array result
is recovered. Exact speed ratios are not guarantees from single samples.

### Analyze commands

Three serial alternating process rounds, with three warmups and 20 timed
commands per fixture per round; medians of 60 samples, excluding process startup:

| Fixture | Original Magix | Deriver 97ad01a | Deriver 5996928 |
|---|---:|---:|---:|
| Project controller | 22.853 ms | 26.451 ms | 25.784 ms |
| Functional composition | 7.455 ms | 10.982 ms | 9.015 ms |
| Expiration | 12.516 ms | 13.722 ms | 12.118 ms |
| Async composition | 7.225 ms | 8.578 ms | 8.281 ms |

All output hashes match. A separate 200-declaration project scan selecting one
entry also has matching output. Its whole-process medians were 289.009 ms,
377.904 ms and 425.926 ms respectively. The latest runs ranged from 313 to
450 ms, and the original from 243 to 387 ms. Shared-host variation prevents
attributing these small command differences precisely to the dependency; no
general speedup claim follows. These measured command latencies are usable for
development tooling, not a capacity guarantee for every application.

## Remaining signed-key limit

The following two source forms produce the same PHP array:

```php
function resolve() { return [-1 => 0, 1, 2, /* ... */ 8191]; }
function resolve() { return ['-1' => 0, 1, 2, /* ... */ 8191]; }
```

The original reader resolves both. With the latest Deriver, the first remains
open/opaque with `MEMORY_LIMIT` and cannot pass `definite()`; the second is exact.
In the integrated shape probe, the first took about 3.44 seconds and returned
unresolved, whereas the second took about 155 ms and returned all 8,192 entries.
Other probes succeeded for 8,192 string keys, 8,192 positive integer keys and
1,024 nested values. These shape timings are single observations.

`-1` parses as `UnaryMinus`, while `'-1'` is a scalar string.
[`LiteralArrayLowering::literal()`](https://github.com/k-kinzal/deriver/blob/5996928a3757e13ccc83250d4048f4ed59e18dc3/src/Source/Compilation/LiteralArrayLowering.php)
recognizes scalar nodes and null/boolean constants but not signed expressions.
One such key therefore sends the whole array back through incremental
`array-set` evaluation. Supporting signed numeric literals safely, or bounding
retention in the general construction path, is the focused next improvement.
No spelling conversion was added to Magix to conceal this difference.

This matters for a valid declared value, not only a raw Deriver program. A
`#[UseStrategy(MapFactory::class, map: [-1 => 0, 1, ..., 8191])]` argument bound
to `create(array $map)` with `array<int, int>` PHPDoc has `state: known` and
8,192 items in original Analyze JSON. It becomes `state: unknown` with the new
integration. The reproducing factory extends `CompositeCacheStrategy` and
returns `parent::compose()`; both analyses recognize it. Its TTL stays 30s and
there are no declaration errors, so this example demonstrates lost argument
information, not an invented incorrect cache lifetime. We use a Strategy map
because cache tags have a list contract; a negatively keyed tags array is not
the appropriate contract example for this remaining issue.

## Validation

- All 33 frozen JSON/Tree/Mermaid reports and 61 literal cases are unchanged.
- All 3,072 numeric differential combinations still match the original reader.
- Two new large-array tests cover 8,192 tags and 8,192 keyed entries with
  collisions. Both pass against the pre-integration reader and the latest
  dependency, protecting the recovered behavior without accepting the remaining
  signed-key failure as a new baseline.
- `composer test`: **3,494 tests / 22,868 assertions**, all passed.
- Configuration, package manifests, security audit and all lint checks pass.
- `composer doc-gen` passes, including strict coverage metadata.
- Precision isolation, warning-free signed infinity and rejection of partial
  or warning-bearing concrete values remain covered.

## Reproduction

The existing [benchmark](deriver-benchmark.php) and
[original-reader setup](deriver-feedback.md#reproduction) apply unchanged.
The [raw Deriver reproducer](deriver-reproducer.php) now accepts a second
argument to contrast the remaining shapes:

```sh
php -d xdebug.mode=off -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-reproducer.php 8192 plain
php -d xdebug.mode=off -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-reproducer.php 8192 negative-key
php -d xdebug.mode=off -d memory_limit=1G \
  packages/magix-cache-cli/docs/deriver-reproducer.php 8192 negative-string-key
```

The JSON records exact source, dependency version, assessment, frontiers and
`definite()` status. All three direct runs had empty stderr. For the full
Strategy-argument reproducer, generate a source fixture as follows and analyze
`DeriverFeedback\MapQuery` with `--format=json --uncached=all`:

```python
from pathlib import Path
items = '-1 => 0,' + ','.join(map(str, range(1, 8192)))
source = r'''<?php
namespace DeriverFeedback;
use Magix\Cache\Attribute\Cache;
use Magix\Cache\Attribute\UseStrategy;
use Magix\Cache\Cacheable;
use Magix\Cache\Cached;
use Magix\Cache\Strategy\CompositeCacheStrategy;
use Magix\Cache\Strategy\StrategyDefinition;
final class MapFactory extends CompositeCacheStrategy {
    /** @param array<int, int> $map */
    public static function create(array $map): StrategyDefinition {
        return parent::compose();
    }
}
final class MapQuery {
    use Cacheable;
    #[Cache(ttl: 30)]
    #[UseStrategy(MapFactory::class, map: [ITEMS])]
    public function execute(): Cached {
        return $this->cached(static fn (): Cached => Cached::of('value'));
    }
}
'''.replace('ITEMS', items)
Path('/tmp/DeriverMapQuery.php').write_text(source)
```

[Machine-readable evidence](deriver-evaluation-5996928.json) preserves timings,
per-round values, output hashes, successful tag counts and the remaining map
argument and raw-memory frontier. Earlier evaluations remain historical records.
