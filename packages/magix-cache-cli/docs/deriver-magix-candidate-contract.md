# What Magix Analyze needs from Deriver candidates

Evaluated on 2026-10-19 against Deriver
`afc98516f758f78c6c43d96e084c040d684b3fb3`, still upstream main when checked.
Magix observations use worktree revision `431ba5a0` and its retained `e59f993`
dependency. Uncommitted changes in the original working directory were not
included or modified. All authored application snippets were read statically.

## Assessment

**Deriver supplies useful candidate information for the Magix questions tested
here, including request-dependent values that never become concrete. A concrete
singleton is not the general acceptance criterion for Analyze.**

The useful integration boundary is a collection of alternatives and structured
dependencies, projected into Magix's metadata domain. A request may determine
which alternative is used without preventing the analyzer from describing all
alternatives, their common fields, or their storage conditions. Deriver should
not be asked to run a request, choose an executable path, or iterate a loop to
completion to provide that information.

No missing required candidate or contradictory concrete candidate was found in
the authored cases. That is a scoped observation, not proof of arbitrary PHP
coverage. Two `min()` calls remain symbolic: their operands and correlations
are retained, and the checker applies Magix's numeric meet to those operands.
They are not reported as native Deriver evaluation successes.

The prior output-equivalence suite remains useful for detecting refactor
changes. It is not the semantic oracle for this evaluation. The existing Magix
reader can lose useful information, and reproducing that loss is not a goal.
The earlier migration prototype only replaces a concrete-value adapter; it
does not implement the richer integration described here. Its timings and the
Console dependency conflict remain deployment facts, separately from candidate
adequacy. No new production adapter or dependency update is included here.

## The questions Analyze must keep separate

1. **Which values does the selected source describe?** Preserve concrete
   alternatives, symbolic operations, input references, field structure and
   shared selections. This is Deriver's source-candidate contract.
2. **Which candidate does this request select?** Usually unknown. A report does
   not need that answer to be useful. A closed set is not one observed value.
3. **What does each candidate mean for caching?** Magix owns expiration,
   visibility, tags, cacheability, composition, override order and validation.
4. **What can be proved across the candidates?** A field common to every
   candidate remains known. A guarantee requires every relevant alternative;
   a single good candidate is only a possibility. Source scope and residual
   dependencies must remain attached to these conclusions.

Default derivation keeps both branches even for a literal true condition.
Neither an unreachable else candidate nor the absence of a computed final loop
value is a defect under the
[source-candidate contract](https://github.com/k-kinzal/deriver/blob/afc98516f758f78c6c43d96e084c040d684b3fb3/docs/queries.md).
Optional predicate pruning is a separate consumer choice. It is not a condition
for accepting Deliver, and is not used by this evaluation's primary oracle.

## Boolean inputs: two different uses

Consider `return $flag ? A : B`, where A describes
`30s / Shared / tags common,a` and B describes
`60s / Private / tags common,b`.

| Candidates for the input value | Default structural result | Optional truth-pruned result | What Analyze can say without selecting a request |
|---|---|---|---|
| `{true, false}` | A and B | A and B | TTL `30/60s`; common tag `common`; per-alternative visibility/tags |
| `{true}` | A and B | A | Same default structural report; the input fact can be retained separately |
| `{false}` | A and B | B | Same default structural report; the input fact can be retained separately |
| An unknown bool input | A and B | A and B | The output candidates are still fully described despite the unknown selector |
| `{true}` plus an unknown bool input | A and B | A and B | A known witness does not justify excluding B |

All five cases were checked. Literal `if (true) ... else ...` also retains both
source TTLs. For the default report, true-only does not mean that the syntactic
else disappears. Narrowing with `BranchEvaluators::truth()` is explicitly
[optional](https://github.com/k-kinzal/deriver/blob/afc98516f758f78c6c43d96e084c040d684b3fb3/docs/evaluators.md).
If Magix later exposes a narrower report, its assumptions and invocation scope
must be explicit; it cannot silently redefine the source report.

An unknown bool input is not the same *derivation* as two witnessed constants.
The former retains an input reference; the latter has a closed witnessed set.
The bool type bounds valid values but does not manufacture source witnesses.
Both can nevertheless produce the same two concrete output branches because
their predicate value is unnecessary for discovering those branches.

For a validated boolean input, Magix can already use the abstract truth domain
`{true,false}` even when Deriver retains an input reference. It need not demand
two concrete witnesses before reporting runtime-dependent cacheability. The
provenance differs, while the possible truth values for that question agree.
An untyped unresolved value does not carry the same type guarantee and must not
silently be treated as a validated boolean metadata value.

Now use the boolean as a value: a normalized metadata record with
`cacheable: $flag`, fixed TTL 30 and fixed Private visibility. The result differs:

| Boolean value candidates | Cacheability information | Other fields |
|---|---|---|
| `{true}` | Known cacheable | TTL and visibility remain known |
| `{false}` | Known non-cacheable | TTL and visibility remain known; storage is prohibited by cacheability |
| `{true, false}` | Two alternatives; caching depends on selection | TTL and visibility remain known |
| Unknown bool | Runtime-dependent cacheability with an input reference | TTL and visibility remain known |
| `{true}` plus unknown | Retain the true candidate and the symbolic alternative | Do not promote true to a guarantee |

Deriver retains exactly this field structure in the probes. These normalized
records test information requirements; they do not claim that the current
Magix reader already imports arbitrary cacheability expressions. The existing
`CacheEffect` does not provide a general candidate-valued cacheability field.

Unknown request selection is therefore not a reason to mark the entire result
as an analysis failure. If A and B both have valid positive finite expiration,
permitted visibility and known cacheability, the selected metadata is not exact
but those common storage prerequisites are provable. This is a static judgement
about valid returned metadata, not a promise that a write actually occurs on
every request.

## Integers and `30 + alpha`

| Information about alpha | Required candidate information | Appropriate Magix interpretation |
|---|---|---|
| Concrete 12 | 42 | Exact 42 if used as a TTL duration |
| Closed `{0, 30}` | `{30, 60}` | `30/60s`; keep the gap, not an invented 30–60 continuum |
| Unknown int | Expression `30 + alpha`, named int dependency | No exact TTL, no justified lower bound of 30 |
| `{0}` plus unknown int | Concrete 30 and symbolic `30 + alpha` | Preserve the known alternative without treating it as exhaustive |
| Closed `{-60, 0}` | `{-30, 30}` | Retain the invalid TTL candidate for validation; do not discard it to report only 30 |
| Proven bounded alpha in `[0, 60]` | Expression plus that justified bound | A consumer can infer `[30, 90]` where arithmetic stays in range |

The first five rows are executable probes. The last row is an explicit consumer
inference requirement, not a claim that Deriver discovers interval bounds in
these tests. An `int` annotation alone does not establish non-negativity; PHP
integer overflow also matters before generalizing arithmetic bounds.

The context where the expression is used changes what can be proved:

- A raw `30 + alpha` expression has no Magix TTL validation contract attached.
  Its literal 30 is an operand, not a lower bound or a reference TTL by itself.
- A successful `#[CacheTtl]` binding supplies a validated non-negative integer
  duration. That establishes a finite runtime duration, including possible zero,
  without deriving the request's exact number. It does not establish `>=30`.
- A readable Strategy contract such as `#[Ttl(min: 30)]` supplies a different,
  declared lower bound. Magix can use that promise without solving the Strategy
  implementation or the request.
- A later explicit TTL replacement removes the old TTL dependency. It does not
  remove dynamic tags or visibility, nor erase separate declaration errors.

For an actual `StrategyDefinition::of(..., ttl: ...)` source call, the public
argument selector derives `{30,60}` for a conditional TTL and preserves
`30 + $offset` for a dynamic TTL. It does not need to execute the factory,
construct a Strategy, or resolve the whole return object. A boolean factory
default remains an unknown input in an open query; an explicitly selected
caller passing true yields true in that selected scope.

## Partial metadata must stay useful

The following normalized record is intentionally not completely resolved:

```php
return [
    'ttl' => 30 + $offset,
    'visibility' => 'private',
    'tags' => ['base', $tag],
];
```

Deriver retains the array fields, the addition operator and its integer input,
the known Private value, and the known `base` tag next to the dynamic tag.
Separate projections yield known Private, the symbolic TTL, and the partial
tag array respectively. Overwriting `ttl` with 60 yields known 60 without
resolving the offset or tag; the tag dependency remains. The checks inspect
field positions, operators and dependency types, not just the presence of some
constants anywhere in the graph.

For tags, `['catalog', 'product:' . $id]` guarantees `catalog` in valid metadata.
The prefix describes a dynamic tag pattern, not a guaranteed concrete tag named
`product:`. A dynamic payload is also not a metadata dependency: projecting the
metadata out of a record with an unknown payload returns the fixed metadata.

Do not convert every `partials` status into one Magix gap. Distinguish:

| Residual information | Consequence for Analyze |
|---|---|
| Recognized external input in an understood expression | Ordinary runtime dependence; retain structure and unaffected fields |
| Recurrence with initial value and update | Useful symbolic candidate information; exact iteration solving is unnecessary |
| Unresolved external producer | Keep known alternatives and mark only fields depending on that producer as incompletely analyzed |
| Enumeration/depth/interruption limit | Candidate coverage may be incomplete; no exhaustive-set guarantee from the known subset |
| Empty selected invocation context | No matching candidates in that context; not TTL zero or unconstrained metadata |
| Concrete null in a normalized expiration field | Known absence of expiration; distinct from unknown and empty context |

The field projection may itself lack support for a symbolic operation. That is
a Magix projection limitation, which should remain identifiable rather than
being reported as a missing Deriver candidate. Likewise, a known bound or
metadata declaration can answer a question even when the entire expression
remains symbolic.

## Alternatives, composition and correlations

Alternatives are a union of possible source results. They are not simultaneous
dependencies. For A=`30/Shared/{common,a}` and B=`60/Private/{common,b}`, keep both
records; the common tag is `common`. Do not report tag union `{common,a,b}` as
present in every alternative or mix A's TTL with B's fields.

Composition uses Magix's meet law on compatible combinations. Combining TTL
sets `{30,60}` and `{10,90}` requires `{10,30,60}`, not set intersection and not
just the minimum 10. If a selected record is reused through an alias, the
selections remain correlated. The tested repeated-operand pairs are
`(30,30)` and `(60,60)`, not the independent cross-product.

These `min()` source examples are not concretized by the current standard
evaluator: Deriver reports a retained call with `MISSING_SOURCE`. It preserves
all four independent argument pairs, or the two correlated alias pairs. The
checker implements only the integer metadata minimum over those retained
pairs, yielding the required results. This is a consumer projection and an
integration requirement, not a claim that all unresolved functions are safe to
interpret. Direct standard support for `min` and more specific unsupported
builtin diagnostics would simplify this use case; no operand loss was found.

Two assignments under **one** `if` retain their shared source selection. Two
separate ternaries using the same request predicate can yield four structural
combinations. Those combinations are allowed by the candidate contract; this
evaluation does not demand predicate equivalence or execution-path solving.

Loops follow the same principle. A fixed assignment loop retains initial 10
and updates 30/60. A foreach over offsets `[0,10,30]` retains TTLs 30/40/60
without picking the final iteration. An unknown iterable keeps initial 30 and
the dependent update. Repeated `ttl += 10` retains initial 30 and the recurrence
update; failure to enumerate a final number is not classified as candidate
loss.

## Observations in the current Magix implementation

The [Magix observer](deriver-magix-analyze.php.example) parses twelve authored
sources through `SourceParser`, `CacheTree` and `JsonRenderer`. It records
behavior rather than using it to define correctness:

| Source shape | Observed Analyze result |
|---|---|
| Request boolean selecting two cached dependencies | `30/60s`, two metadata alternatives, common tag retained, no gap |
| Literal true, literal false, or request superglobal condition | Same structural alternatives |
| Both branches return the same cached metadata | Known 30s and known fields; one metadata alternative |
| Parent explicitly sets TTL 90 | Known 90s; alternative visibility/tags remain |
| TTL 0 or 30 | `0/30s`, storage runtime-dependent |
| No-expiration alternative or TTL 30 | Both alternatives retained; no common finite-expiration proof |
| Known cache result or opaque transformation | Known alternative retained beside partial metadata; field causes recorded |
| Fixed loop updates between two cached results | `30/60s`, both metadata alternatives, no gap |
| Request `#[CacheTtl] int $ttl = 30` | Finite runtime duration, lower bound 0 (`dynamic` in the overview); known Private and tags; default not substituted for input |
| Explicit `new CacheMetadata(...)` with dynamic expiration but fixed tags/Private | All metadata fields partial; fixed tags and visibility are lost |

The final case is a concrete Magix precision limitation. The reader currently
recognizes omitted metadata and reuse of another result's `metadata`; it does
not interpret this constructor shape. The loss belongs to that reader/semantic
projection. The normalized Deriver record probes show that partial structures
can preserve the needed fields, but they are not an end-to-end implementation
for actual CacheMetadata constructors, absolute expiration or the full runtime.

The existing `ExpressionDeriver::source()` returns the same unresolved sentinel
for `{30,60}` and `30 + unknown`. Its singleton policy is appropriate for the
limited literal-reading interface it currently serves. It would be inadequate
as the result domain for broader Analyze integration. Magix's separate metadata
flow reader already retains alternatives, so this observation does not mean
all current Analyze alternatives are being collapsed by that adapter.

## Integration acceptance criteria

Before extending the integration, preserve these properties:

1. Carry concrete alternatives and symbolic terms into analysis, instead of
   requiring one native value. Keep source/argument scope and provenance.
2. Project metadata field by field. Retain constants, input types, operations,
   bounds established by Magix contracts, and dependencies only where relevant.
3. Keep request selection, understood dynamic values and incomplete analysis
   distinct. `interrupted: false` alone is not a completeness test; residual
   budget reasons and opaque dependencies also matter.
4. Apply alternative union, compatible dependency products, ordered field
   replacement and validation in Magix's domain. Preserve correlations where
   the source candidate representation supplies them.
5. Keep known alternatives beside unknown ones in JSON. Summary guarantees
   use facts common to every relevant candidate; references are not proofs.
6. Never promote a selected caller's values or a default parameter to all
   request inputs. A report must identify the source and invocation scope it
   answers, including unresolved dispatch and external entry inputs.

This is a change in the internal analysis interface, not a request for Deliver
to become an interpreter. The general bridge is not implemented by the earlier
singleton migration patch. Nor does this report establish that importing every
Deriver term kind into Magix is already possible without further work.

## Evidence and reproduction

The [expectation manifest](deriver-magix-candidates.json) defines 52 authored
cases from candidate and metadata requirements. The
[runner](deriver-magix-candidates.php.example) records default structural and
optional truth-pruned results. The [checker](deriver-magix-candidates-check.py)
checks 161 obligations, including no invented concrete values, partial field
structure and correlated operands. All authored obligations were satisfied.
The two retained `min` cases are explicitly distinguished from concrete output.
The [results](deriver-magix-candidate-results.json) retain compact evidence and
the current Magix observations.

These are focused examples, not random testing, whole-project coverage or a
proof of engine completeness. The experiment does not evaluate user code or
use PHP execution as the expected-answer generator.

Use the isolated installation from the
[earlier setup](deriver-evaluation-afc9851.md#reproduction-and-handoff), then:

```sh
php -d xdebug.mode=off \
  packages/magix-cache-cli/docs/deriver-magix-candidates.php.example \
  "$evaluation_dir/vendor/autoload.php" \
  packages/magix-cache-cli/docs/deriver-magix-candidates.json > "$evaluation_dir/candidates.json"
python3 packages/magix-cache-cli/docs/deriver-magix-candidates-check.py \
  packages/magix-cache-cli/docs/deriver-magix-candidates.json "$evaluation_dir/candidates.json"
php -d xdebug.mode=off \
  packages/magix-cache-cli/docs/deriver-magix-analyze.php.example vendor/autoload.php
```

Raw terms/evidence and Magix JSON are retained locally in ignored
`build/deriver-evaluation/magix-candidate-contract-20261019.tar.gz`. Reproduction
scripts and expectations are committed and can be shared upstream. No issue
was submitted automatically. Production code and dependency versions were not
changed by this follow-up.

Repository checks: Composer configuration/package validation, security audit
and lint pass. The initial eight-process test run hit the existing one-second
limit in two StrategyAnalysis tests. That file passes independently (3 tests,
16 assertions); `composer test -- --processes=2` then passes the full suite
(3,497 tests, 22,874 assertions). Test time limits were not changed. These checks
validate the retained production implementation, not the semantic completeness
of the proposed candidate bridge.
