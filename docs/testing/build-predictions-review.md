# Build Predictions review

## Files changed

- `app/Http/Controllers/Admin/NhlModelRunController.php`
- `app/Models/NhlModelRun.php`
- `app/Jobs/BuildNhlSatModelEntityProfilesJob.php`
- `app/Jobs/LoadNhlSatModelProfileBatchJob.php`
- `app/Jobs/BuildNhlSatModelEntityRateProjectionsJob.php`
- `app/Jobs/BuildNhlSatModelEntityToiProjectionsJob.php`
- `app/Jobs/BuildNhlSatModelEntityProfileForEntityJob.php`
- `app/Jobs/BuildNhlSatModelEntityRateProjectionForEntityJob.php`
- `app/Jobs/BuildNhlSatModelEntityToiProjectionForEntityJob.php`
- `resources/views/admin/nhl-sat-models/_model-row.blade.php`
- `routes/web.php`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `tests/Feature/NhlBuildPredictionsTest.php` (full test source)
- `docs/testing/build-predictions-review.md`

## Minimum coverage matrix

COVERED means assertions have been authored, not executed.

| Behavior | Coverage | Test names |
| --- | --- | --- |
| Same-model start; HTTP request → persistence → HTTP progress read | COVERED | starts profiles on the same model and exposes the running stage through HTTP |
| Normal form submission | COVERED | supports the normal form redirect |
| Preconditions | COVERED | requires evaluated SAT inputs; requires training seasons; rejects models outside the SAT workflow; returns not found for a missing model |
| Overlap prevention | COVERED | rejects duplicate prediction requests; rejects a model with work already running; blocks competing manual profile and evaluation actions during the combined build |
| Dependency order | COVERED | advances successful profiles to rates without completing the run; advances successful rates to TOI; completes only after TOI succeeds |
| Parent dispatch is not completion | COVERED | does not start rates merely because the profiles parent has dispatched its batch |
| Stage failures | COVERED | stops on profile batch failure; stops on rate batch failure and keeps the failed stage; records TOI failure instead of successful completion; records a parent job failure in the combined build |
| Cancellation and empty input | COVERED | treats a cancelled batch as failure before advancing; marks an empty profile batch as failed instead of building from old outputs |
| Callback identity and idempotence | COVERED | ignores duplicate batch completion callbacks; ignores callbacks from an older prediction build; does not advance an out of order stage; does not execute a parent stage from a stale build |
| Legacy single-stage action | COVERED | keeps standalone rate builds outside the combined workflow |
| Live row contract and failure escaping | COVERED | exposes persisted current stage counts in refreshed row HTML; shows the failed stage and escapes its error in the model row |
| PostgreSQL parameter limit regression | COVERED | queues more than the old parameter limit in bounded database inserts |
| All training and snapshot identities retained | COVERED | preserves training and snapshot identities across chunk boundaries |
| Loader cancellation and stale execution | COVERED | does not prepare or queue work for a cancelled profile loader; does not prepare or queue work for a stale profile loader; stops adding chunks when a profile batch is cancelled during loading |
| Partial insert failure; action → persisted error → HTTP read | COVERED | cancels a partially loaded batch and records a short SQL error through HTTP |
| Bounded diagnostics and callback ordering | COVERED | bounds ordinary profile failure messages as well as database exceptions; preserves loader error details when the worker and batch report the same failure again |
| Loader prevents premature stage completion | COVERED | keeps the profile batch pending until the loader and all children succeed |
| Standalone profile compatibility | COVERED | keeps standalone profiles on the same bounded loading path |

## Requirements checklist

1. SATISFIED in source: Build Predictions appears in the existing model action menu and uses the existing authenticated form handler.
2. SATISFIED in source: Profiles, /60, then TOI/GP reuse the existing batches on the same run; no training or model creation occurs.
3. SATISFIED in source: batch success gates advancement; failures, cancellation and empty stages stop the combined build.
4. SATISFIED in source: run reservations and transitions use row locks; competing mutation requests cannot claim an already-running model.
5. SATISFIED in source: build ID and current-stage checks reject stale/duplicate completion callbacks; progress broadcasts occur every 25 completed entities and at stage completion.
6. SATISFIED in source: standalone actions retain their independent paths; a new manual action clears old combined-build display state.
7. SATISFIED in documentation: orchestration belongs to existing model/job workflow authority, with metrics values documented in ENUMS.
8. UNSATISFIED at runtime: tests/builds/queues have not been run, per repository execution policy. No actual model data was rebuilt.
9. SATISFIED in source: profile queue inserts and child-job allocation are limited to groups of 100; the existing builders still return entity identifier lists in memory. The loader payload contains only model/build identifiers, not those lists. This is bounded job submission, not a new persisted entity-discovery system.
10. SATISFIED in source: the loader is a pending member of the same batch, so early child completion cannot advance the workflow while more jobs are being added. Progress totals count profile/snapshot entities, excluding the loader.
11. SATISFIED in source: loader errors cancel partial work, stop advancement, skip generic-stability generation from failed batches, and preserve a short diagnostic before completion callbacks. Database SQL/bindings and the original exception graph are omitted from rethrown loader errors; queued/expected counts are retained.

## Endpoint × verb authorization/scope

| Endpoint | Verb | Access | Test |
| --- | --- | --- | --- |
| `/admin/nhl-sat-models/{run}/predictions/build` | POST | Guest blocked | blocks guests from starting prediction builds |
| Same | POST | Ordinary user blocked | blocks ordinary users from starting prediction builds |
| Same | POST | Super admin allowed | starts profiles on the same model and exposes the running stage through HTTP |
| `/admin/nhl-sat-models` | GET | Guest blocked | blocks guests from viewing prediction build progress |
| Same | GET | Ordinary user blocked | blocks ordinary users from viewing prediction build progress |
| Same | GET | Super admin allowed, persisted progress visible | exposes persisted current stage counts in refreshed row HTML |

Organization/owner scope is not applicable: SAT model administration is global and super-admin-only. Existing middleware and CSRF protection remain in place. Lifecycle readiness is mocked as seeded for these tests; authentication and super-admin middleware remain enabled.

## Frontend contract

No JavaScript files changed. The new form reuses the existing SAT model form submission selector. Blade renders accessible stage/progress text, disables the combined action while running, and escapes error content. HTTP and broadcast-payload assertions cover the rendered contract. Existing menu motion and app layout are reused.

## Per-file test count

`tests/Feature/NhlBuildPredictionsTest.php`: **40 `it(...)` declarations**. Frozen clock, HTTP blocked, normal dispatch faked; stage transitions use deterministic IDs. Two regression tests deliberately use the database queue and batch repository inside the isolated test database: one verifies 11,001 child jobs produce inserts with at most 600 bindings, and the other records child/loader completions without running a worker. No tests were executed.

The queue regression changes touch only the profile parent, its new loader, this test file, this review, `NhlModelRuns.yaml`, and the derived architecture inventory. The remaining files above belong to the preceding Build Predictions implementation. No schema, dependency, PHP memory setting, prediction formula, or existing queue contents were changed.

Framework reference: [Laravel batch loader jobs](https://laravel.com/docs/12.x/queues#adding-jobs-to-batches). Operational validation is still required; static review does not establish a measured memory ceiling.

Human-run command:

```sh
php artisan test tests/Feature/NhlBuildPredictionsTest.php
```

Static review included `git diff --check`; it does not establish runtime correctness. After review and tests, Build Predictions will replace selected-model profile/projection rows through the existing builders. No such operational command was run during implementation.

Awaiting human review.
