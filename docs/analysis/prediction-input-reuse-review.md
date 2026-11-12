# Saved pregame aggregate reuse

Operational preparation now reads player EV/PP game counts, exposure seconds and
overall retained SAT/SOG/goal totals from the existing model/game/player context
snapshot. It no longer accumulates personal Train/season exposure and appearance
counts or recomputes those rolling-window totals for publication. Player L20 PP
goals/game also uses the saved numerator and appearance count with the existing
personal Train and league fallback order.

The snapshot must contain individual-strength metrics and all requested windows
at the target cutoff. Empty windows remain unavailable; recent windows with
missing appearances remain unavailable; usable Train samples remain supported.
Zero-strength appearances retain their game count and zero exposure. Missing or
malformed saved fields produce an explicit input error rather than silently
rebuilding the missing aggregate.

The additional calculations remain: individual bucket counts/conversions,
Train defensive fitting, exposure-weighted historical opponents, retained-bucket
team PP goals against and team/league opportunity and PK references. The team
snapshot already supplies its saved general metrics and schedule. Its generic
team totals cannot substitute for these unstored retained-bucket and special-teams
references. No team snapshot fields, raw facts or database tables change.

The bounded historical pass remains necessary for those additional inputs. This
change removes duplicated aggregates, not the pass itself, and makes no measured
runtime improvement claim. Transient standalone forecasting and chronological
Test replay retain their original aggregation path.

The preparation pipeline version changes to invalidate older cached input
manifests/checkpoints. The pregame context version and freshness definition are
unchanged, so this version change alone does not request another context build.
No scoring coefficient, queue, route, authorization, cutoff or migration changes.

## Coverage matrix

Coverage below means authored assertions, not executed or passing tests.

| Category | Coverage |
|---|---|
| A validation | `does not silently replay missing or invalid saved individual aggregates` covers missing version/window/field and negative exposure |
| B source selection | `reads saved player exposure and overall totals while preserving the separate bucket history`; `uses saved player PP goals per appearance for the L20 offense allocation` |
| C persistence/read contract | Existing context fixtures persist individual-strength metrics; `does not accumulate duplicated personal counts or exposure during operational history preparation` asserts the saved counts reach the output while raw bucket counts remain |
| D output contract | Tests assert usage, history totals, bucket conversion/reference values, PP offense and chosen source fields |
| E HTTP | Not changed; shared input service only, no new endpoint |
| F queued workflow | Existing `builds queued inputs from saved contexts in bounded pages without reading target outcomes` and retry/checkpoint tests remain |
| G frontend | Not applicable; no frontend edits |

## Requirements checklist

- Saved player counts, exposure and overall totals are reused for operational predictions.
- Personal scalar accumulation is disabled while additional bucket accumulation remains.
- PP offense uses the saved player goal numerator and game denominator.
- Tests cover usable Train samples, incomplete recent windows and zero PP exposure.
- Saved parent links/cutoffs and existing lineup selection remain enforced.
- Additional defensive, bucket and league fallback work remains.
- Historical replay retains its original aggregation behavior.
- No production action, migration, test or CI command was run.
- Output equivalence and runtime performance remain unverified until human-run tests.

## Authorization/scope and frontend checklists

No endpoint, verb, authorization, organization scope or frontend component
changes. Existing API/admin authorization matrices remain applicable; no new
matrix cells or frontend state transitions are introduced.

## Per-file test counts

`tests/Feature/NhlCustomGamePredictionTest.php`: 91 authored `it` declarations,
including six added declarations. Dataset cases expand the number of executions.
The full suite remains in the repository. Tests were not run. Static review uses
`git diff --check` only.

After review, the human may run:

```sh
php artisan test tests/Feature/NhlCustomGamePredictionTest.php
```


## Partitioned preparation checkpoints — November 12, 2026

Approved follow-up: replace the oversized single Redis reader checkpoint with
bounded parts while retaining the exact calculation and existing in-flight
preparation identity. Production diagnosis found repeated PHP 512 MB exhaustion
while handling a roughly 98 MB serialized checkpoint. The existing shared Redis
retry_after is 3,900 seconds; it is unchanged by this work.

### Files changed

- `app/Services/NhlCustomGameForecast.php`: bounded framed/checksummed cache parts,
  optional compression, commit-last progress manifest, legacy conversion,
  phase-specific target context loading, expiration/cleanup and small diagnostics.
- `app/Services/NhlCustomGamePredictionInputs.php`: explicit state export/restore
  without changing bucket accumulation or equations.
- `app/Services/NhlCustomGameDefense.php`: explicit fit state export/restore.
- `tests/Feature/NhlCustomGamePredictionTest.php`: deterministic regression cases
  plus partial-mock compatibility for the existing continuation test.
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`: canonical checkpoint rules.
- `docs/ENUMS.md`: internal storage format discriminator, distinct from algorithm version.
- `docs/ARCHITECTURE_INVENTORY.md`: derived summary.
- This review document.

### Behavior and limits

No migration, dependency change, queue configuration change, worker restart or
production recovery operation. Keep both `custom_ev_pp_v4` and the existing input
pipeline version unchanged: this storage change must not invalidate valid pending
progress. A matching legacy reader is converted before advancing history; a
changed statistical/lineup/model fingerprint retains the existing clean-rebuild
behavior. Conversion preserves available progress, not obsolete or expired state.

Parts are at most 512 KiB before compression. The encoder bounds subtree inspection
before serialization, partitions large strings, preserves numeric types and array
ordering, and validates each part by length/hash before reading its records. Reader
and fit fields are exported explicitly, avoiding one giant object serialization.
Only the current target's saved context is hydrated during target processing;
other contexts remain in immutable parts, renewed with progress. History still
loads its required accumulated bucket/defense state; this is not a change to the
historical calculations or a move of those metrics into pregame tables.

New generations publish parts before the progress manifest. Partial writes leave
the preceding committed generation intact. Old parts are removed only after the
replacement manifest commits. Abandoned unpublished parts expire. Successful input
publication and invalid-source completion discard the owned preparation parts.
Diagnostics expose stage, cursor, part count, maximum/total part bytes, save time
and process peak memory internally. Actual production peak-memory and elapsed-time
improvement has not been measured. No tests or operational commands were run.

### Finalized minimum coverage matrix

COVERED means assertions authored, not tests executed/passed.

| Area | Status | Named test |
| --- | --- | --- |
| State/type/count restoration and bounded part size | COVERED | round trips a preparation reader through bounded parts without changing counts or types |
| Large nested buckets/strings and compact manifest | COVERED | splits large strings and nested bucket arrays without a monolithic cached value |
| Legacy cursor/count preservation | COVERED | restores a legacy checkpoint at its cursor before converting it without replay |
| Operational conversion before another historical page | COVERED | converts matching operational legacy preparation before advancing another historical page |
| Fitted defensive values | COVERED | retains fit accumulators and fitted allowance values through partitioned restoration |
| Full phase traversal and identical final inputs/picks/goals/probabilities | COVERED | produces identical game inputs and engine outcomes across partitioned page boundaries |
| Deferred context retention, per-target loading and required-context integrity | COVERED | loads only the selected targets saved context while retaining the other targets parts; does not hydrate deferred contexts that are not needed for the current history page; requires an intact saved context when its target phase is reached |
| Missing/corrupt data | COVERED | rejects missing or corrupt checkpoint parts without publishing guessed state (dataset) |
| Failed part/manifest write cannot advance cursor | COVERED | keeps the preceding committed cursor when a part or manifest write fails (dataset) |
| Replacement cleanup and TTL | COVERED | expires abandoned checkpoint parts without evicting a live committed generation |
| Stale source requires clean rebuild, without decoding stale data | COVERED | does not decode stale checkpoint parts when source evidence requires a clean rebuild |
| Diagnostic presence, internal-only data | COVERED | records bounded preparation diagnostics without exposing bucket state |
| Queue routing/release, source cutoffs and preparation dependency handling | COVERED | existing builds queued inputs from saved contexts in bounded pages without reading target outcomes; releases preparation work immediately after progress and polls pending context gently; skips the pregame dependency queue when preparing a future date through the shared forecast boundary |

### Requirements checklist

1. SATISFIED in code/assertions: bounded independent serialized parts replace a monolithic reader.
2. SATISFIED in code/assertions: deferred target contexts hydrate only when required.
3. SATISFIED in code/assertions: manifest commits last and interrupted writes preserve progress.
4. SATISFIED in code/assertions: matching legacy checkpoints retain cursor/counts before resuming.
5. SATISFIED in code/assertions: numerical inputs and calculated outcomes remain identical.
6. SATISFIED in code/assertions: integrity, lifetime and source-fingerprint rules are retained.
7. SATISFIED in code/assertions: internal stage/cursor/size/peak-memory diagnostics are recorded.
8. SATISFIED in scope: no migrations, production recovery or worker/configuration changes performed.

### Endpoint × verb authorization/scope matrix

No endpoint, auth or request-validation logic changes. Existing mapped coverage:

| Endpoint / verb | Case | Existing named test |
| --- | --- | --- |
| GET `/api/nhl-game-predictions` | Guest denied | requires API authentication for new engine predictions |
| Same | Missing NHL scope denied | requires the NHL statistics scope for new engine predictions |
| Same | Scoped cold request → queued continuation → saved revision → HTTP read | automatically saves and publishes the prediction when queued dependencies become ready |
| Admin operational endpoints, GET/POST | Guest denied | blocks guests on every operational endpoint |
| Same | Ordinary user denied | blocks ordinary users on every operational endpoint |
| POST manual runs | Super-admin allowed | admits one date and persists the requesting admin and stack |
| Organization/owner scope | Not applicable | Shared NHL facts with existing permissions; no tenant changes |

Frontend component checklist: N/A; no frontend files changed.
Per-file report: `tests/Feature/NhlCustomGamePredictionTest.php` contains **128**
`it(...)` declarations (114 existing, 14 added; dataset expansion excluded).
Tests remain unrun under repository policy. Suggested human verification:

```sh
php artisan test tests/Feature/NhlCustomGamePredictionTest.php
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
php artisan test tests/Feature/NhlOperationalGamePredictionsTest.php
```

After human review and deployment, verify a matching legacy checkpoint converts
at its existing cursor, then advances with diagnostics comfortably below PHP's
512 MB limit. Any recovery of reserved jobs, exhausted deadlines or worker
restarts is a separate explicitly approved operational action. Existing saved
predictions, pregame context and algorithm configuration must remain unchanged.


### Timestamp compatibility correction — November 12, 2026

Production legacy context evidence contains an Illuminate Support Carbon object
at `evidence.{game}.history_identity.model.1`. The first partition encoder rejected
this model update timestamp; the preceding legacy checkpoint remained intact.
The approved correction encodes known native/Carbon date types as scalar records
and restores their original class, instant, microseconds and named timezone.
General object deserialization remains restricted to stdClass. Unsupported values
now identify their path/type; arbitrary date classes remain rejected. Prediction
math, source JSON fingerprints, cursor, queue settings and schema stay unchanged.
No production jobs are recovered or rerun by this correction.

Changed files: `app/Services/NhlCustomGameForecast.php`,
`tests/Feature/NhlCustomGamePredictionTest.php`,
`docs/architecture/stats/NhlCustomGamePrediction.yaml`, and this review document.

| Minimum coverage | Status | Named test |
| --- | --- | --- |
| Five supported date types, microseconds, named zone and DST offset; JSON evidence unchanged | COVERED (authored) | preserves supported timestamp classes precision and DST timezone through checkpoint parts (five-case dataset) |
| Actual legacy evidence structure and 3,275 cursor survive conversion | COVERED (authored) | converts the production legacy evidence timestamp while preserving its history cursor and fingerprint |
| Arbitrary object/date rejection and actionable path/type | COVERED (authored) | rejects arbitrary checkpoint objects and date classes with an identifiable error |

Requirements: 1. SATISFIED in code/assertions: accept the actual Carbon model
timestamp. 2. SATISFIED: preserve class, date values, timezone, precision and JSON
fingerprint. 3. SATISFIED: keep prior cursor and restrict reconstructed types.
Endpoint/auth/scope matrix above remains applicable; no HTTP or authorization
change. Frontend checklist: N/A. The touched Pest file now has **131** declarations;
all exceed the twenty-test minimum. Tests were not run under repository policy.
No migration required. Deployment and production recovery remain separate.
