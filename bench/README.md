# All-miss overhead

```bash
composer install
composer bench:quick
composer bench
```

The question is how much time Magix Cache adds when **every cache boundary
misses**, compared with the same PHP computation without Magix Cache.
The suite follows php-ai-toolkit's
[PHPBench skill](https://github.com/k-kinzal/php-ai-toolkit/blob/f2d25c654067a586a5c207cd49283b0390c60509/skills/setup-toolkit-phpbench/SKILL.md),
with the comparison changed to two implementations on the same revision.
It is informational, with no merge-target comparison or regression gate.

## Workload

| Parameter set | Tree depth | Cache boundaries | Origin calculations |
|---|---:|---:|---:|
| `single-1-boundary` | 0 | 1 | 1 |
| `composed-3-boundaries` | 1 | 3 | 2 |
| `composed-15-boundaries` | 3 | 15 | 8 |

Each leaf totals 32 fixed catalog prices with deterministic quantities derived
from the input ID. Each parent adds its two children's totals. `PlainQuery`
returns integers and has no Magix Cache calls. `MagixQuery` wraps every node in
`Cacheable::cached()` with `#[Cache(ttl: 60)]`, and parents use `combine2()->map()`.
Both subjects consume the final integer into the same observable result field.

Each revolution increments the root ID, including warm-up revolutions. Child IDs
are `2 * id` and `2 * id + 1`; at any given depth, distinct roots therefore have
disjoint child IDs. Both ID and depth enter the default cache key. Every lookup
misses even after previous revolutions have populated the cache.

`MemoryCache` implements the core storage port with an array: it really retains
entries and can return a hit when a key is reused. There is no forced-miss stub,
zero TTL, NoStore policy, external service, PSR adapter, or serialization step.
A fixed PSR clock keeps all written entries fresh throughout a sample.

## Measurement boundary

One operation is one **complete query tree**, including the final `value()` call
in the Magix case. The measured path includes call-site capture, key generation,
runtime and strategy execution, missed reads, origin computation, metadata and
policy composition, and writes to the array. No diagnostic observer runs while
timing.

PHPBench constructs the catalog, queries, empty cache, clock, and runtime in a
before hook outside the timed region. The input counter resets there, and the
registry is cleared after the sample. Each sample gets independent storage;
entries accumulate only within that sample. Warm-up primes class loading and
declaration memoization using different keys from the measured calls. These are
**warm-declaration misses**, not cold PHP process or first-declaration timings.

The plain subject uses 100,000 revolutions and the Magix subject 5,000, keeping
timing intervals useful without retaining excessive cache data. The origin's
five-ID quantity cycle is identical in both subjects. PHPBench normalizes times
per revolution. Full runs use 10 iterations, two warm-up revolutions, and a 5%
retry threshold; quick runs use three iterations, one warm-up revolution, and a
10% retry threshold. These are sampling settings, not performance budgets.
Xdebug and OPcache are disabled in the measurement subprocesses for both subjects.

## Reading the output

The aggregate report gives the mode and relative standard deviation (`rstdev`)
for each subject and workload. The overhead report pairs the two subjects by
workload and displays:

- `plain`: time without Magix Cache;
- `all_miss`: time with every boundary missing;
- `additional`: `all_miss - plain`, the added time per complete query tree;
- `ratio`: `all_miss / plain` (for example, `3x` means three times the duration).

All comparisons use modes from the same run. Pay attention to `rstdev` and repeat
full runs on an idle machine. The origin is deliberately cheap in-process work:
the ratio describes these fixtures, not the slowdown of an application with
database or network work. The additional time is usually the more useful number.
Backend, serialization, actual system-clock construction, hit-rate benefits, and
request bootstrap costs require separate measurements.

To save raw samples and environment-specific results:

```bash
mkdir -p build/phpbench
composer bench -- --tag=all_miss --dump-file=build/phpbench/results.xml
```

For a single workload, keep both subjects selected so they can be paired:

```bash
composer bench -- --variant=single-1-boundary
```

For one subject in isolation, use PHPBench's aggregate report directly:

```bash
vendor/bin/phpbench run --filter=benchMagixCacheAllMiss --report=aggregate
```

Local overrides belong in ignored `phpbench.json`; the shared configuration is
`phpbench.json.dist`. Results under `build/phpbench/` are also ignored.

The separate GitHub Actions benchmark job runs on PHP 8.3 for pull requests and
manual dispatches, publishes both reports to the job summary, and uploads XML,
storage, console output, the lock file, configuration, and environment metadata.
It fails on execution errors but applies no speed threshold. Correctness tests
in the core package check equal results, a miss and store at every node across
successive inputs, and a real hit when an earlier input is repeated.

PHPBench 1.7 supports the project's PHP 8.3–8.5 development environments. Its
required `doctrine/annotations` package is abandoned, so Composer records a
package-specific abandonment exception with a removal condition. Security
advisory auditing remains enabled; the suite itself uses PHP attributes.
