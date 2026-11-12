# Discovery status ownership — November 12, 2026

Approved scope: prevent historical import failures in the selected dates from
making a newly requested discovery appear failed before its own stages exist.
Production discovery 218 was running with no run-level error, while the screen
initially borrowed legacy stale errors from August 28 in its season date range.

## Files changed

- `app/Http/Controllers/Admin/NhlGameImportController.php`
- `tests/Feature/NhlGameImportRunControlTest.php`
- `docs/architecture/admin/AdminNhlGameImports.yaml`
- `docs/architecture/imports/NhlDiscoveryPipeline.yaml`
- This review document.

`progressQueryForRun` now always filters discoveries by their own run ID, even
when empty. The redundant processed-discovery empty-query branch is removed.
Non-discovery legacy date-range behavior is unchanged. Existing status computation,
run controls, data and processing pipelines remain unchanged. Actual owned-stage
processing failures and run-level discovery failures remain visible. No migration,
UI changes, operational commands or automatic retries are introduced.

## Finalized minimum coverage matrix

COVERED describes authored assertions, not executed or passing tests.

| Contract | Status | Named test |
| --- | --- | --- |
| Discovery POST → persisted queued run → status GET, legacy errors unchanged | COVERED | admits a discovery without borrowing historical stale errors and returns its own status over HTTP |
| Empty owned stages retain real queued/running/completed/failed lifecycle | COVERED | retains a discovery lifecycle before its first stage despite other errors in the date range (dataset) |
| Owned stage counts exclude overlapping and legacy progress | COVERED | excludes overlapping runs and unowned stages once discovery seeds its first own stage |
| Genuine current processing failures remain visible | COVERED | keeps genuine current run processing failures visible instead of showing historical errors |
| Processed discovery without owned rows never falls back to old dates | COVERED | does not borrow old stage rows when a processed discovery no longer owns progress |
| Read authorization | COVERED | protects discovery status reads from guests and non-admin users |
| Queue contract | COVERED | admits a discovery without borrowing historical stale errors and returns its own status over HTTP asserts discovery dispatch and absence of orchestrator dispatch |

## Requirements checklist

1. SATISFIED in code/assertions: scope discovery counts/errors to current run.
2. SATISFIED in code/assertions: preserve empty-stage discovery lifecycle and own errors.
3. SATISFIED in code/assertions: retain actual processing errors and historical records.
4. SATISFIED in scope: no migration, queue operation, status enum or UI behavior changes.
5. SATISFIED in docs: canonical admin/discovery rules now state empty-run ownership.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Case | Named test |
| --- | --- | --- |
| GET `/admin/nhl-game-imports/status` | Guest 401, ordinary user 403 | protects discovery status reads from guests and non-admin users |
| Same | Super-admin 200 | admits a discovery without borrowing historical stale errors and returns its own status over HTTP |
| POST `/admin/nhl-game-imports/discover` | Guest 401, ordinary user 403 | protects discovery admission from guests and non-admin users |
| Same | Super-admin 202 and DB/read contract | admits a discovery without borrowing historical stale errors and returns its own status over HTTP |
| Organization/owner scope | N/A | Existing super-admin global operational data; no new permission or tenant scope |

Frontend component checklist: N/A; no frontend files changed.
Per-file count: `tests/Feature/NhlGameImportRunControlTest.php` has **60** test
declarations, including existing nested tests, excluding dataset expansion.
`git diff --check` passes. Tests were not run under repository execution policy.
Human verification command:

```sh
php artisan test tests/Feature/NhlGameImportRunControlTest.php
```

No production records or jobs were modified during implementation.
