# Pregame statistical freshness review

Approved scope: stop completed OFF games entering the live boxscore cadence,
avoid unchanged goalie-summary writes, and distinguish real source changes from
routine sync timestamps. No migration is required. No tests, CI, imports, queue
workers, production rebuilds or other operational commands were run for this
implementation.

## Files changed

- `app/Services/AdminImportSchedules.php`
- `app/Services/ImportNhlBoxscore.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `config/nhl-custom-prediction.php`
- `tests/Feature/NhlAvailabilityApiTest.php`
- `tests/Feature/NhlStrengthOnIceStatsTest.php`
- `tests/Feature/NhlModelPregameBuildTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/architecture/admin/AdminImportRegistry.yaml`
- `docs/architecture/admin/AdminImportSchedule.yaml`
- `docs/architecture/imports/NhlGameDataImportServices.yaml`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/ENUMS.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/new-engine-prediction.md`
- `docs/ARCHITECTURE_INVENTORY.md` (freshness section only)
- This review document.

Unrelated local import-index work, its migration/schema/test changes and Finder
files remain outside this scope.

## Findings and resulting behavior

Production inspection found all six November 8 games repeatedly selected by
`nhl:sync-today-boxscores` because the eligibility filter excluded FINAL but
included OFF. At 9:38 a.m. Toronto the command updated boxscore-sync timestamps
and 24 goalie-summary rows. Raw boxscore values had last changed around 4 a.m.,
before the most recent context build. The shutout update issued an unconditional
bulk write, while freshness treated MAX(updated_at) as new statistical evidence.

- Routine boxscore selection excludes FINAL and OFF. The existing separate final
  PBP reconciliation and explicit imports/correction commands remain available.
- Shutout updates write only changed or previously unknown values. Goalie-decision
  refreshes save dirty values only, returning the actual changed-row count.
- Shared result evidence hashes selected game statistical/schedule values and
  summary content, excluding record creation/update and polling timestamps.
- Existing expiring cache entries keep per-game summary digests. Touched-game
  metadata identifies which digest must be recomputed; it is not itself included
  in the resulting statistical fingerprint. Reads use the configured bounded
  game-page size. PostgreSQL row transaction revisions additionally detect
  changes within timestamp precision. The portable test path uses timestamp and
  row-count metadata.
- Successful import publication conservatively invalidates derived evidence even
  when summary totals are unchanged. Processed PBP versions also invalidate
  bucket corrections independently of totals; observation timestamps do not.
- Context, queued input preparation and public response caching share this
  evidence. Lineup structure and goalie identity/status remain separate inputs;
  repeated identical observations do not create revisions merely by being newer.
- Response namespace and input-preparation versions advance to reject prior
  timestamp-based cache entries. Saved prediction revisions retain their exact
  historical evidence. Existing context gets the new fingerprints when refreshed
  through Update Pregame or prediction-triggered work after deployment.
- Scoring equations, roster-source rules, queue allocations, routes, access checks,
  tables and model definitions are unchanged. Cold digest calculation and
  production throughput remain unmeasured.

## Minimum coverage matrix

COVERED means authored assertions, not executed or passing tests.

| Category | Status | Named coverage |
|---|---|---|
| A validation/auth | COVERED | Retained `requires API authentication for new engine predictions`, `requires the NHL statistics scope for new engine predictions`, and existing import-schedule authorization tests |
| B scheduling/defaults | COVERED | Extended `keeps yesterday unfinished games on the scheduler while excluding final and older games` includes both FINAL and OFF while preserving unfinished eligibility and cadence |
| C persistence | COVERED | `does not rewrite an unchanged goalie shutout or boxscore summary during repeated refreshes`; `writes goalie decisions only when their stored values change` |
| D API/cache contract | COVERED | `retains a published revision after timestamp only syncs and rebuilds after an actual source correction` verifies the real response-cache/history boundary |
| E HTTP read | COVERED | Same API test performs GET publication, database timestamp updates, GET with the same revision, real stat correction, GET with a new revision and persisted revision counts |
| F derived publication | COVERED | `invalidates pregame evidence when derived facts are republished by a completed import`; `invalidates on processed PBP corrections while ignoring repeated observation timestamps` |
| G frontend | NOT APPLICABLE | No frontend changes or new UI state. Existing HTTP pending/fresh/stale contracts remain unchanged. |
| Statistical fidelity | COVERED | `keeps pregame fingerprints stable when sync timestamps change without statistical changes`; `invalidates pregame fingerprints when real result values are corrected`; `detects added and removed historical participants without using target outcomes`; `keeps lineup changes independent from unchanged historical statistics` |

## Requirements checklist

1. SATISFIED in authored code: exclude both finished states from routine boxscore
   polling while preserving separate corrections.
2. SATISFIED in authored code: unchanged goalie shutout/decision values preserve
   their timestamps; real corrections still persist.
3. SATISFIED in authored code: generic polling/update timestamps do not invalidate
   statistical evidence or published prediction revisions on their own.
4. SATISFIED in authored code: actual scores, opportunities, player values,
   participant additions/removals and lineup changes invalidate affected evidence.
5. SATISFIED in authored code: completed import publication and processed PBP
   corrections invalidate derived inputs even if summary totals remain unchanged.
6. SATISFIED in authored code: share one evidence definition across context,
   prepared inputs and public response cache, with expiring bounded digest reuse.
7. SATISFIED in authored code: preserve schema, roster-source rules, statistical
   cutoffs, scoring equations and immutable history.
8. UNVERIFIED: tests, live queue behavior and production performance; not run under
   the repository execution policy.

## Endpoint x verb x authorization/scope

| Endpoint | Verb | Scope | Named coverage |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | Guest denied | `requires API authentication for new engine predictions` |
| Same | GET | Authenticated without NHL stats scope denied | `requires the NHL statistics scope for new engine predictions` |
| Same | GET | NHL stats scope allowed | `retains a published revision after timestamp only syncs and rebuilds after an actual source correction` |
| Import schedule action | PUT | Guest denied | Existing `blocks guests from updating admin import schedules` in unchanged `AdminControlPanelTriageTest.php` |
| Same | PUT | Non-admin denied | Existing `blocks non-admin users from updating admin import schedules` in that suite |
| Same | PUT | Super-admin allowed | Existing `allows super admins to configure the today game boxscore schedule` in that suite |

These are global NHL data workflows; no organization/ownership rule is introduced.
The source-data fingerprint helper exposes no new HTTP endpoint.

## Frontend checklist

No JavaScript, views, motion or global frontend state changed. API response shapes,
HTTP 202 preparation behavior and one-/five-minute response age limits remain.

## Per-file authored test counts

Counts include nested `it(...)` declarations before dataset expansion.

| File | Count |
|---|---:|
| `tests/Feature/NhlAvailabilityApiTest.php` | 173 |
| `tests/Feature/NhlStrengthOnIceStatsTest.php` | 69 |
| `tests/Feature/NhlModelPregameBuildTest.php` | 106 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 53 |

All modified suites exceed the twenty-test minimum. Full authored test files are
available at the listed paths. Only `git diff --check` was used for static
whitespace verification. The human can run the repository-approved Pest command
scoped to these four suites after review. No accuracy or runtime-performance
claim is made by this unexecuted change set.
