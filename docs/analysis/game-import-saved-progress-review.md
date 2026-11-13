# Saved Game Imports progress

Approved scope: save progress summaries when import work advances, read those
values in the dashboard, and load game rows only when their run is expanded.
Reuse the existing run payload. No migration, new queue, worker configuration,
prediction or pregame calculation change is required.

## Files changed

- `app/Events/NhlGameImportStatusUpdated.php`
- `app/Listeners/RefreshNhlImportProgressSummary.php`
- `app/Repositories/NhlImportProgressRepo.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/NhlGameImportRunControl.php`
- `app/Http/Controllers/Admin/NhlGameImportController.php`
- `routes/web.php`
- `resources/js/admin/admin-hub.js`
- `resources/js/pages/Admin/Dashboard.vue`
- `tests/Feature/NhlGameImportSummaryTest.php`
- `tests/Feature/AdminControlPanelTriageTest.php`
- `resources/js/admin/admin-hub.test.js`
- `docs/architecture/imports/ImportProgressRepository.yaml`
- `docs/architecture/admin/AdminNhlGameImports.yaml`
- `docs/architecture/admin/ImportBroadcastStream.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

## Resulting behavior and limits

`nhl_import_progress` remains authoritative. Each existing run payload may contain
`progress_summary` version 1 with source status counts, owned-stage total, latest
failure metadata, discovery facts, actionable rerun count and per-game categories.
Projection publication reads the current committed source instead of blindly
incrementing counters, so repeat notifications cannot double count a stage.
It locks the parent with `FOR NO KEY UPDATE`, preserves current run-control and
other payload metadata, leaves lifecycle timestamps unchanged and emits no
recursive invalidation. New run generations discard the old projection.

Repository notifications register an after-commit projection update before
broadcast delivery, independently of Reverb availability. The event listener
also covers existing direct lifecycle/status notifications; its per-dispatch
guard prevents the same event from publishing twice. The guard is excluded by
the unchanged curated broadcast payload. Rollback discards deferred publication.
Validation model saves/deletions refresh initialized rerun-count projections in
their existing date scopes. No new background queue or automatic repair run is
started. Out-of-band SQL changes require subsequent publication or initialization.

Status keeps existing pagination, duplicate-card precedence, run controls,
specialized scoped/batch progress and date/ownership rules. Prepared runs no
longer perform per-run source count/error/fact queries on every poll. A visible
older run missing the projection initializes once during its first read. Older
uninitialized duplicate candidates use one grouped owned-stage compatibility
lookup; the global scheduled-date count and season list remain small live reads.
Run selection still reads existing run metadata before duplicate collapsing.
This does not claim to eliminate every database read or provide measured latency.

Default `GET /admin/nhl-game-imports/status` returns `games: []` with a run-specific
`games_url`. The super-admin-protected `GET /admin/nhl-game-imports/{run}/games`
returns `{run_id, games}` for that selected run. Explicit `include_games=1` keeps
inline details available for existing admin consumers and regression fixtures.
Action responses retain their existing inline behavior. Completed runs with
actionable skipped/failed stages remain expandable before game rows are loaded.

The browser retains detail rows separately from summary responses, fetches only
expanded runs, keeps initial loading/error feedback in the existing empty-row
area and preserves displayed rows during background refresh. Status requests and
same-run detail requests cannot overlap. A queued page change keeps the latest
selection. Existing five-second polling, Reverb invalidation and disclosure motion
remain in place. No global JavaScript state or new UI banner is introduced.

## Minimum coverage matrix

COVERED means authored or retained assertions; tests have not been executed.

| Dimension | Status | Named coverage |
| --- | --- | --- |
| A: guest and ordinary-user denial, permitted admin, unknown run and invalid optional flag | COVERED | denies guests the saved import status endpoint; denies ordinary users the saved import status endpoint; denies guests the lazy game details endpoint; denies ordinary users the lazy game details endpoint; allows a super admin to read owned game diagnostics separately; returns not found for an unknown lazy detail run; rejects malformed optional inline detail requests |
| B: discovery ownership, explicit legacy fallback, isolation and duplicate events | COVERED | does not borrow date-range stages for an empty discovery snapshot; retains explicit legacy process date-range compatibility; keeps lazy game diagnostics isolated to their owning discovery run; does not double count repeated source notifications; publishes a shared notification projection only once within the same dispatch |
| C: source mutation → saved projection, metadata/time preservation, rollback | COVERED | publishes a completed source mutation into saved status and subsequent HTTP reads; preserves current cancellation and checkpoint metadata while publishing counts; does not change the run lifecycle timestamp when publishing a summary; rolls back a summary publication with its surrounding transaction |
| D/E: default lightweight payload, explicit details, failure diagnostics, saved-read query bounds | COVERED | initializes an older visible run once and returns its saved progress without games; retains explicit inline game details for existing admin consumers; stores latest failure diagnostics alongside source counts; reads prepared status without per-run source counting or loading details |
| E: HTTP action → database assertions → HTTP status | COVERED | preserves saved progress across an HTTP pause database update and subsequent status read |
| F: feedback scope and curated broadcast contract | COVERED | keeps source publications scoped when another run shares the same game; keeps projection bookkeeping out of the existing broadcast payload |
| G: collapsed/expanded fetch, retained rows, retry, wrong-run rejection and request coalescing | COVERED | loads only summaries while run accordions are collapsed; loads one run on expansion and retains its rows after closing; keeps a per-run initial loading state and retries failures on reopen; rejects another runs detail payload without replacing retained rows; coalesces overlapping status polls into a single active request; retains the latest selected page while an earlier status request is active |
| G: summary before details and accessible initial feedback | COVERED | uses saved game category counts even when no game rows have loaded; keeps completed runs with actionable skipped stages expandable before details load; keeps disclosure loading feedback in the existing empty-row space |

## Requirements checklist

1. SATISFIED in code/assertions: persist summaries in the existing run payload.
2. SATISFIED in code/assertions: source progress remains authoritative and repeat-safe.
3. SATISFIED in code/assertions: retain run ownership, controls, metadata and rollback.
4. SATISFIED in code/assertions: prepared polls omit per-run recount/detail work.
5. SATISFIED in code/assertions: expanded runs load authorized game details on demand.
6. SATISFIED in code/assertions: preserve explicit inline detail compatibility.
7. SATISFIED in code/assertions: coalesce requests and retain existing displayed rows.
8. SATISFIED in documentation: architecture and derived inventory describe the change.
9. NOT EXECUTED: automated suites, browser verification and production timing.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Case | Test |
| --- | --- | --- |
| GET `/admin/nhl-game-imports/status` | Guest denied 401 | denies guests the saved import status endpoint |
| Same | Ordinary authenticated user denied 403 | denies ordinary users the saved import status endpoint |
| Same | Super-admin allowed | initializes an older visible run once and returns its saved progress without games |
| GET `/admin/nhl-game-imports/{run}/games` | Guest denied 401 | denies guests the lazy game details endpoint |
| Same | Ordinary authenticated user denied 403 | denies ordinary users the lazy game details endpoint |
| Same | Super-admin allowed and run ownership retained | allows a super admin to read owned game diagnostics separately; keeps lazy game diagnostics isolated to their owning discovery run |
| Same | Unknown run 404 | returns not found for an unknown lazy detail run |
| Existing POST pause | Existing authorization unchanged | NhlGameImportRunControlTest: denies guests access to all run controls; denies non-admin users access to all run controls; allows an admin to pause and reads the paused status through the existing status endpoint |
| Tenant/organization scope | N/A | Existing global super-admin operations; no tenant payload or new permission |

## Frontend component checklist

- Deterministic mocked fetch responses; no provider calls or real timers.
- Collapse/expand and caret semantics retained; loading/error state is run-local.
- Loaded rows survive summary replacement and failed background detail refreshes.
- Wrong-run responses cannot replace another run's diagnostics.
- Only one status request and one request per expanded run may be active.
- Latest page selection is retained while prior work resolves.
- Existing Vue disclosure motion and reduced-motion classes remain unchanged.
- Existing legacy inline fixtures continue to exercise their original assertions.

## Per-file test declaration counts and human commands

- `tests/Feature/NhlGameImportSummaryTest.php`: **28** `it(...)` declarations.
- `tests/Feature/AdminControlPanelTriageTest.php`: **235**; nineteen existing status
  calls now explicitly request inline details for their existing assertions.
- `resources/js/admin/admin-hub.test.js`: **115**; nine new deterministic cases.

All counts exclude dataset expansion and exceed twenty. No CI, tests, migrations,
imports, syncs or worker operations were run for this change. Static review uses
`git diff --check`. Human commands:

```sh
php artisan test tests/Feature/NhlGameImportSummaryTest.php
php artisan test tests/Feature/AdminControlPanelTriageTest.php
php artisan test tests/Feature/NhlGameImportRunControlTest.php
npx vitest run resources/js/admin/admin-hub.test.js
```

No migration or explicit data rebuild is required. Existing source rows remain
intact, and older visible summaries initialize on their first request. Production
deployment and measured performance remain for human review.
