# SAT_V2 goalie ratings and post-sync preparation

## Files changed

- `app/Services/NhlGoalieProjectionRefresh.php`
- `app/Jobs/RefreshNhlGoalieProjectionsJob.php`
- `app/Listeners/QueueNhlGoalieProjectionRefresh.php`
- `app/Services/NhlGamePredictionPayload.php`
- `tests/Feature/NhlGoalieProjectionRefreshTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/architecture/stats/NhlSatGoalieRatings.yaml`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlGoalieSeasonProjections.yaml`
- `docs/architecture/stats/NhlGoalieWorkloadProjections.yaml`
- `docs/architecture/stats/NhlStatsCacheRefresh.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- This review document.

The current custom-engine API no longer assembles its diagnostic goalie edge from
legacy season projections. Each new payload uses the same SAT model and exact
starter IDs already selected by the forecast. Current matchup team labels do not
need to match a goalie's historical profile team. The seventy/thirty edge weights,
null matchup-fit term and game forecast calculations are unchanged. Diagnostic
values change because their source is now the selected SAT model.

Stored regular-season, all-strength goalie training buckets provide pooled xGA,
GA and their difference, divided by one shared goalie exposure and multiplied by
3,600. Only the model's retained Train-confidence >=50% bucket keys are included.
Per-bucket copies of exposure are not summed, and EV/PP/PK copies are not counted
again. Per-game API rating fields identify their sixty-minute comparison basis;
these fields are not season workloads or next-game GA forecasts.

The existing committed season-sync event now prepares the production SAT model's
rating snapshot in the shared cache. It does not dispatch legacy workload,
performance, defensive-profile or G-profile builds. No historical source facts are
reaggregated or model retrained. Cache keys are scoped by SAT model and retained
bucket set, replaced in place, and invalidated by profile publication metadata.
A missing snapshot can be prepared directly from stored profiles during a new
prediction calculation. Failed/active builds retain existing prepared snapshots.

Missing personal evidence remains unavailable. A zero measured GSAx is valid;
zero from absent history is not invented evidence. Unprofiled prospects do not
block preparation for the modeled goalies. A wholly absent usable profile build
fails the post-sync preparation visibly. Old serialized coordinator stages and
completion callbacks redirect safely to SAT ratings instead of publishing legacy
season rows. The existing admin progress, error and completion gates remain used.

The unpushed legacy-model-pinning changes have been replaced. The manual legacy
projection builder and per-goalie worker were restored to their committed forms.
The two missing legacy profiles discovered during diagnosis are not prerequisites
of this new rating pathway. No operational data preparation was performed.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests. Short names refer to the
exact test names in the indicated suites.

| Contract | Status | Test names |
| --- | --- | --- |
| Existing event and production-only model dispatch | COVERED | Refresh: listens to the existing season event after commit; queues the production SAT model on projections without an admin run; does not substitute September testing models for the named production model |
| Season eligibility and duplicate admin delivery | COVERED | Refresh: does not apply later training history to earlier seasons; coalesces duplicate deliveries for the same admin sync |
| Correct pooled per-60 math, retained keys, strength and model isolation | COVERED | Refresh: calculates GSAx per sixty from pooled totals and one exposure denominator; uses only retained model buckets and avoids double counting strength splits; keeps model histories isolated when explicitly calculating testing-model ratings |
| Signed/zero evidence, inconsistent or absent exposure, missing starters | COVERED | Refresh: keeps signed and genuinely zero goalie ratings available; marks unusable personal evidence unavailable instead of inventing zero skill; does not combine bucket rates with inconsistent exposure; keeps missing starters and missing personal profiles explicitly unavailable |
| Current identity/team and source provenance | COVERED | Refresh: retains the selected goalie and current team independently of stored historical team context |
| Cache reuse/invalidation, active builds and publisher contention | COVERED | Refresh: reuses prepared ratings until the profile publication changes; does not publish partial ratings during an active SAT profile build; retains a prior snapshot while the same SAT model is rebuilding; defers worker preparation when another snapshot publisher holds the lock |
| No legacy builders, profile prerequisites or prospect-wide gate | COVERED | Refresh: forces fresh preparation during season sync without rebuilding profiles; reports a wholly missing SAT goalie build without requiring legacy profiles; does not let a missing unprofiled prospect block the modeled goalie snapshot |
| Serialized old jobs/callbacks and abandoned legacy drafts | COVERED | Refresh: redirects old serialized workload and performance stages to SAT ratings; redirects old completion callbacks without publishing legacy rows; keeps old automatic projection drafts excluded from remaining legacy reads |
| Progress, completion and first-error retention | COVERED | Refresh: reports readiness only after successful rating publication and other sync work; preserves failure and cache state across repeated error callbacks; waits for both cache and goalie preparation before completing season sync |
| API calculation → revision DB payload → subsequent saved HTTP read | COVERED | API: publishes individual goalie edge values through revision storage and saved API reads |
| Conflicting legacy values ignored; selected model/IDs retained | COVERED | API: publishes individual goalie edge values through revision storage and saved API reads; isolates the API edge to the forecast model and its exact selected starters |
| Unavailable individual evidence and unchanged edge formula | COVERED | API: keeps goalie edge evidence unavailable when one selected starter lacks SAT history; exposes both weighted goalie contributions without changing the existing edge calculation; preserves unavailable goalie evidence while exposing the unchanged zero contributions |
| Predicted scores, totals, probabilities, winner and confidence retained | COVERED | API: publishes individual goalie edge values through revision storage and saved API reads |
| HTTP sync submission → preparation failure DB status → authorized HTTP read | COVERED | Refresh: exposes a sync-triggered rating preparation failure through the authorized status endpoint |

## Requirements checklist

1. SATISFIED: automatic production rating preparation selects SAT_V2, not the September testing model.
2. SATISFIED: new API diagnostics follow the forecast's exact model and starter identities.
3. SATISFIED: derive a consistent sixty-minute GSAx comparison from stored retained goalie profiles.
4. SATISFIED: retain edge weights and keep game prediction calculations unchanged.
5. SATISFIED: stop automatic legacy projection builds and redirect old queued coordinator stages.
6. SATISFIED: preserve diagnostic availability, cache publication and admin failure visibility.
7. SATISFIED: update canonical architecture, enum meanings and the official API usage document.
8. SATISFIED: preserve saved revisions; no migration, operational write, test execution or push.

## Endpoint × verb authorization/scope checklist

No endpoint or permission was added or changed.

| Endpoint / verb | Case | Test |
| --- | --- | --- |
| GET `/api/nhl-game-predictions` | Guest 401 | API: requires API authentication for new engine predictions |
| Same | Wrong scope 403 | API: requires the NHL statistics scope for new engine predictions |
| Same | Read-scoped calculation, immutable revision and saved read | API: publishes individual goalie edge values through revision storage and saved API reads |
| POST `admin.nhl-game-imports.season-sync` | Guest 401; ordinary user 403 | Refresh: retains guest and ordinary-user restrictions on sync and progress |
| Same | Super-admin request and subsequent preparation | Refresh: exposes a sync-triggered rating preparation failure through the authorized status endpoint |
| GET `admin.nhl-game-imports.status` | Guest 401; ordinary user 403 | Refresh: retains guest and ordinary-user restrictions on sync and progress |
| Same | Super-admin reads HTTP-created run's persisted failure | Refresh: exposes a sync-triggered rating preparation failure through the authorized status endpoint |
| Organization/owner scope | N/A | Existing global NHL API scopes and super-admin-only import administration retained |

## Frontend component checklist

Not applicable: no frontend files changed and no new loading messages or layout shifts.

## Per-file test counts and checks

- `tests/Feature/NhlGoalieProjectionRefreshTest.php`: **28** `it(...)` declarations,
  **41** cases after dataset expansion. Obsolete legacy-pipeline assertions were
  replaced with the approved SAT rating pathway's contracts.
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: **88** `it(...)`
  declarations. One existing lifecycle fixture now uses SAT profiles and deliberately
  conflicting legacy records; two new regression declarations cover source identity
  and missing personal evidence. Other existing assertions are retained.

Checks performed: source/dependency and schema inspection, test declaration counts,
static code/fixture review and `git diff --check`. A read-only production SELECT
confirmed 132 usable SAT_V2 goalie histories under the same 94 retained bucket
keys and exposure checks. This was source-data inspection, not a prediction run.
Tests and CI were not run under
repository policy. No production jobs, profile builds, predictions or data writes ran.

Suggested human checks:

```sh
php artisan test tests/Feature/NhlGoalieProjectionRefreshTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlStatsDashboardTest.php tests/Unit/ProjectionQueueRoutingTest.php
```

No migration is required. After deployment and the normal worker reload, the next
season sync prepares the SAT_V2 rating snapshot. New prediction calculations use
that source. Existing saved predictions retain their previous diagnostic values
until a separately requested new prediction is calculated.
