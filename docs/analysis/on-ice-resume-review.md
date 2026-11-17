# Resume failed on-ice runs

Files changed or created:

- `app/Services/NhlGameImportRunControl.php`
- `resources/js/admin/admin-hub.js`
- `tests/Feature/NhlGameImportRunControlTest.php`
- `resources/js/admin/admin-hub.test.js`
- `docs/architecture/imports/NhlGameImportRunControl.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/on-ice-resume-review.md`

## Coverage matrix

Coverage below is authored and manually reviewed, not executed.

| Contract | Status | Test names |
| --- | --- | --- |
| Resume → saved state → HTTP progress read | COVERED | resumes failed on-ice work through HTTP with only unfinished games and reads preserved progress |
| Guest/member denial; admin admission | COVERED | keeps failed on-ice Resume restricted to super-admins (guest/member cases); resumes failed on-ice work through HTTP with only unfinished games and reads preserved progress |
| Scope/terminal restrictions | COVERED | does not grant failed-run recovery to other process scopes (three cases); does not recover a failed on-ice run whose completion and sync were already recorded; rejects Resume and deletion while cancellation cleanup is pending; rejects Pause and Cancel for a completed run |
| Live/expired claims and saved results | COVERED | waits for live claims before recovering failed on-ice work; clears expired claims while preserving failed-run game checkpoints |
| New batch, queue and stamped generation | COVERED | resumes failed on-ice work through HTTP with only unfinished games and reads preserved progress; stamps recovery jobs from the locked generation without a parent read per game |
| Old jobs, callbacks and duplicate actions | COVERED | fences old queued work and old failure callbacks after recovery; rejects a duplicate failed-run Resume after the first recovery is published; retains newly completed checkpoints if recovered work fails and is resumed again |
| Admission failure and concurrent cancellation | COVERED | leaves a failed on-ice run retryable if fresh batch dispatch fails; keeps cancellation authoritative during failed-run recovery preparation |
| Normal paused flow and finalization | COVERED | resumes only unfinished on-ice games in the original batch; finalizes recovered checkpoints exactly once without rebuilding completed games; queues one tracked Season Sync after all games complete and rejects duplicate completion |
| Frontend control visibility and authoritative state | COVERED | offers Resume for a failed on-ice run while leaving other failed scopes unchanged; uses authoritative failed status for recovery and hides Resume after finalization |
| Existing endpoint, busy guards and errors | COVERED | resumes failed on-ice work through the existing control endpoint and refreshes its progress; keeps failed-run recovery retryable after an HTTP error and blocks duplicate presses |

## Requirements checklist

1. SATISFIED: only failed `process` runs with `process_scope=on_ice` and no
   recorded finalization gain the additional Resume path. Existing normal paused
   runs retain their original batch behavior and authorization.
2. SATISFIED: wait for live claims; clear expired claim bookkeeping; rotate the
   generation before admission so stale queued work and callbacks cannot revive
   the old attempt. Concurrent Cancel retains authority.
3. SATISFIED: retain selected games, completed checkpoints, skipped-source counts
   and imported results. Build a fresh games-processing batch for unfinished
   games, with the current generation stamped from the locked parent.
4. SATISFIED: retain canonical success/failure callbacks and exactly-once season
   sync. Clear current errors only with successful publication; dispatch failure
   restores a retryable Failed state. Original failed-job history is not deleted.
5. SATISFIED: show Resume using the existing control buttons, request endpoint,
   scoped busy/error feedback, broadcasts and authoritative progress refresh.
6. SATISFIED: no migration, queue-worker operations, production-run mutations,
   source reimport, whole-run reset or automatic resume performed by Codex.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Scope | Test names |
| --- | --- | --- |
| POST /admin/nhl-game-imports/{run}/resume | Guest denied, 401 | keeps failed on-ice Resume restricted to super-admins (guest case) |
| Same | Ordinary member denied, 403 | keeps failed on-ice Resume restricted to super-admins (member case) |
| Same | Super-admin allowed for eligible persisted run | resumes failed on-ice work through HTTP with only unfinished games and reads preserved progress |
| Same | Invalid lifecycle/scope denied, 422 | waits for live claims before recovering failed on-ice work; does not grant failed-run recovery to other process scopes; does not recover a failed on-ice run whose completion and sync were already recorded |
| GET /admin/nhl-game-imports/status | Guest/member denied | denies guests the saved import status endpoint; denies ordinary users the saved import status endpoint (existing NhlGameImportSummaryTest.php) |
| Same | Super-admin reads saved recovery counts | resumes failed on-ice work through HTTP with only unfinished games and reads preserved progress |

These are global super-admin operational records, not tenant-owned resources.
No authentication, permission, route, controller response or ownership rules change.

## Frontend checklist

- Existing Vue control row and motion/focus/busy styles remain unchanged.
- Failed on-ice rows offer Resume; other failed scopes and finalized rows do not.
- Existing stored lifecycle state remains authoritative over inferred coverage.
- Recovery uses the existing endpoint and refreshes authoritative progress.
- Duplicate presses are suppressed; HTTP failures clear busy state for retry.
- Mocked requests and explicit fixtures; no new timers or global application state.

## Test counts and checks

- `tests/Feature/NhlGameImportRunControlTest.php`: 73 `it(...)` declarations.
- `resources/js/admin/admin-hub.test.js`: 137 `it(...)` declarations.

Only source/diff review and `git diff --check` were performed. Tests, CI, builds,
browser checks and production operations were not run. No migration required.
After deployment, the operator can press Resume on the existing failed on-ice
row to continue its saved selection; this change does not perform that action.
