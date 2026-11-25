# Manual lineup prediction refresh

Approved scope: trigger prediction work after an accepted manual lineup saves
through DIQ's game page or Gner8's existing submission endpoint. No outbound
notification, lineup-ID prediction reuse, schema change or scoring change.

## Files

- `app/Services/NhlAnticipatedLineupImporter.php`: register a refresh only after
  the shared manual importer persists a new observation.
- `app/Services/NhlGamePredictionResponseCache.php`: after-commit admission to the
  existing deduplicated refresh job on `lineups`, without synchronous model reads.
- `tests/Feature/NhlManualLineupPredictionRefreshTest.php`: deterministic coverage
  for both write paths, authorization, validation, commit timing and queue behavior.
- `docs/architecture/imports/NhlAnticipatedLineups.yaml`: canonical write trigger.
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`: refresh boundary.
- `docs/integrations/DIQ-API-Usage-Doc.md`: partner behavior.
- `docs/ARCHITECTURE_INVENTORY.md`: derived summary.
- This review.

## Coverage matrix

Status describes authored assertions, not executed results.

| Category | Status | Tests |
| --- | --- | --- |
| A: Request validation | COVERED | Incomplete roster, duplicate player, supported out injury, cross-team player, outside-matchup team and missing game reject without refresh. |
| B: Normalization/defaults | COVERED | Refresh carries only the canonical NHL game ID and uses `lineups`. |
| C: Persistence | COVERED | Saved manual observation remains current; request does not append a prediction; queued refresh owns existing input admission. |
| D: API contract | COVERED | Successful partner save retains its existing response and no inline prediction; rejected submissions preserve HTTP errors. |
| E: HTTP read | COVERED | Partner POST → current-row assertion → GET `/api/nhl/lineups/{id}` verifies the same accepted player and manual source. |
| F: Queue/commit boundary | COVERED | Both submission paths, dispatch deferred until commit, real rollback, deduplication, changed skater/deployment/starter and absent prior prediction. |
| G: Frontend | Not applicable | No JavaScript or component changes. |

## Requirements

1. SATISFIED in authored coverage: both manual entrypoints share the new trigger.
2. SATISFIED in authored coverage: rejected writes do not queue refreshes.
3. SATISFIED in authored coverage: queue only after commit; rollback leaves no
   pending job or prediction admission.
4. SATISFIED in authored coverage: retain existing queue, game input and admission
   safeguards. Repeated queue admissions coalesce.
5. SATISFIED in authored coverage: no model lookup, scoring or publication runs
   inline with the save request; no provider/network request is permitted.
6. SATISFIED by scope review: existing refresh job recalculates current fingerprints,
   prepares dependencies when needed and publishes immutable revisions. A matching
   fresh response may still be reused by that worker. No new historical lookup or
   lineup-ID reuse mechanism was added.
7. SATISFIED by scope review: no partner webhook, callback or outbound delivery.
8. SATISFIED by scope review: API reads still return the latest saved successful
   prediction while replacement work proceeds. This does not change read policy.

## Endpoint × verb authorization/scope

| Endpoint | Guest | Insufficient permission | Authorized | Team scope |
| --- | --- | --- | --- | --- |
| POST `/api/nhl-lineups` | `does not queue anything when the partner omits authentication` | `does not queue anything when the partner lacks lineup write scope` | `queues a prediction after an authorized partner lineup has committed` | `does not queue a refresh for a team outside the matchup` |
| POST `/games/{id}/lineup` | `does not queue anything for a guest game page submission` | `does not queue anything for a game page member without super admin permission` | `queues a prediction after a super admin game page lineup has committed` | `does not queue a refresh for a game page team outside the matchup` |
| GET `/api/nhl/lineups/{id}` | Unchanged existing read authorization | Unchanged existing `nhl-stats:read` authorization | `retains the accepted manual roster in storage and in the partner read endpoint` | Same requested NHL game. |

Organization/member ownership is not part of these global NHL game resources.
Existing authorization and validation suites remain applicable and unchanged.

## Frontend checklist

Not applicable: no frontend files, state transitions or DOM changes.

## Per-file count and verification

`tests/Feature/NhlManualLineupPredictionRefreshTest.php`: **28 `it(...)` cases**.
The clock is frozen, the array cache is isolated, dispatch is faked and external
HTTP is prohibited. Most commit callbacks are held explicitly to assert before
and after dispatch; the rollback case restores the real database manager.

Tests, CI, lint, builds, migrations and operational commands were **not run** per
repository policy. Source review and `git diff --check` were performed. No
migration is required. Suggested human-run verification:

```sh
php artisan test tests/Feature/NhlManualLineupPredictionRefreshTest.php tests/Feature/NhlLineupSubmissionsApiTest.php tests/Feature/NhlLineupInjurySafetyTest.php
```

No production data, workers or queued jobs were changed directly.
