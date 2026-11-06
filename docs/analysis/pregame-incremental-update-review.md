# Incremental pregame update review

Approved scope: add Update Pregame and make manual/automatic updates maintain only
the model's Test season, or its prediction season when Test is unset. No migration,
worker operation, import, prediction run or test execution was performed.

## Changed files

- `app/Models/NhlModelRun.php`
- `app/Services/NhlPregameContextOrchestrator.php`
- `app/Services/ScheduledProcessManager.php`
- `app/Http/Controllers/Admin/NhlPregameContextController.php`
- `routes/web.php`
- `resources/views/admin/nhl-sat-models/_model-row.blade.php`
- `resources/views/admin/nhl-sat-models/_pregame-progress.blade.php`
- `resources/js/admin/nhl-sat-models.js`
- `resources/js/admin/nhl-sat-models.test.js`
- `tests/Feature/NhlModelPregameBuildTest.php`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (incremental update section only)
- This review document.

Unrelated local import-index work and Finder files are excluded from this scope.

## Minimum coverage matrix

COVERED below means authored assertions, not executed/passing tests.

| Category | Status | Named coverage |
|---|---|---|
| A validation/auth | COVERED | `rejects updates without a resolvable Test or prediction season`; existing guest/non-super-admin/non-SAT datasets include the new route |
| B normalization | COVERED | `updates the prediction season when production has no Test season`; `prefers Test over a separately configured prediction season for updates` |
| C persistence | COVERED | `fills gaps before the latest existing date and new trailing dates without rewriting Train`; `does not accept another models snapshots as complete context` |
| D response | COVERED | `queues a manual update only for Test and exposes its status through HTTP`; `supports the existing non JSON redirect for manual updates` |
| E HTTP read | COVERED | POST update -> inspect saved run -> prepare -> GET progress in `ignores unavailable league comparisons when stored context is structurally complete` |
| F queue | COVERED | `uses the same target season update for scheduled production models`; `leaves overlapping scheduled updates due without creating another run`; projections dispatch assertion in manual-update test |
| G frontend | COVERED | `locks both pregame actions while %s`; `reenables Update Pregame when the incremental run is up to date`; retained polling and error tests |

## Requirements checklist

1. SATISFIED in authored code: manual action immediately below Build Pregame.
2. SATISFIED in authored code: Test takes precedence; explicit/derived model prediction season is fallback.
3. SATISFIED in authored code: Train remains calculation input without selecting Train for updates.
4. SATISFIED in authored code: internal gaps and trailing missing completed games are selected.
5. SATISFIED in authored code: saved normalized metrics and unfinished game work define completeness; legitimately unavailable league comparisons do not force rebuilding.
6. SATISFIED in authored code: manual and scheduled updates share admission, queue and run controls.
7. SATISFIED in authored code: empty selection reports up-to-date without league/context dispatch.
8. SATISFIED in authored code: full Build Pregame scope remains unchanged.
9. Execution pending: test suites are authored and not run, per repository execution policy.

## Endpoint × verb × authorization/scope

Existing `auth`, verification and super-admin route-group protection is retained.

| Endpoint | Verb | Scope | Test |
|---|---|---|---|
| `/admin/nhl-sat-models/{run}/context/update` | POST | Guest denied | `denies dashboard actions to guests` update-route dataset |
| Same | POST | Authenticated non-super-admin denied | `denies dashboard actions to non super admins` update-route dataset |
| Same | POST | Super-admin allowed for selected model | `queues a manual update only for Test and exposes its status through HTTP` |
| Same | POST | Non-SAT model denied | `rejects non SAT models` update-route dataset |
| Same | POST | Non-training registry denied | `rejects updates for a model outside the training registry` |
| Same | POST | Model evidence isolation | `does not accept another models snapshots as complete context` |
| Existing context progress | GET | Existing access protection | Existing authorization datasets plus POST-driven progress assertions above |

No organization/owner restriction applies to these global super-admin actions.

## Frontend contract

- New form retains CSRF, native POST redirect fallback and existing AJAX toast/progress handling.
- No global JavaScript state, provider calls, page reload or new motion pattern.
- Active/paused work disables both build/update actions; terminal progress reenables them.
- The existing form/row identity survives progress replacement; polling stays serial.
- Existing Pause, Resume, Stop, schedule and worker allocations are retained.

## Authored test counts and operator verification

- `tests/Feature/NhlModelPregameBuildTest.php`: **87** `it(...)` declarations.
- `resources/js/admin/nhl-sat-models.test.js`: **23** `it`/`it.each` declarations.
- Dataset cases expand those counts. Both files exceed the twenty-test minimum.
- Full changed test files are available at those paths; no tests or CI commands were run.
- Static whitespace check: `git diff --check` only.

An already admitted run keeps its frozen season scope. Deploying this change does
not cancel or retarget production run 13. Stop the old run through the existing
control before starting a new Update Pregame run, and reload projections workers
through the normal deployment process so new deliveries use the updated code.
