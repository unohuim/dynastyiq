# Saved prediction lineup API review

Approved scope: expose a prediction's selected lineup to partner lineup reads,
including STL's complete projected roster when no current lineup report exists.
Do not change valid manual input selection or prediction equations.

## Files

- `app/Services/NhlAnticipatedLineupPayload.php`: batched latest-successful
  revision lookup for API presentation only; return complete eligible snapshots
  and source revision metadata without creating report rows.
- `app/Http/Controllers/Api/NhlAnticipatedLineupsController.php`: opt into the same
  snapshot preference used by both schedule API shapes.
- `tests/Feature/NhlPredictionLineupReadTest.php`: 30 deterministic Pest cases.
- `docs/architecture/imports/NhlAnticipatedLineups.yaml` and
  `docs/architecture/integrations/ServerToServerApiClients.yaml`: canonical scope.
- `docs/integrations/DIQ-API-Usage-Doc.md`: response semantics and example.
- `docs/ARCHITECTURE_INVENTORY.md`: derived summary.
- This review.

## Coverage matrix

Coverage describes authored assertions, not executed test results.

| Category | Status | Evidence |
| --- | --- | --- |
| A: Validation/scope | COVERED | Wrong game/team, missing game, malformed saved JSON, read-scope and guest refusals. |
| B: Selection/defaults | COVERED | Latest successful revision, failed-attempt exclusion, source attribution and true absence. |
| C: Persistence | COVERED | Real revision publication precedes reads; no synthetic observation/current row and no revision rewrite. |
| D: API contract | COVERED | Three partner shapes carry the same calculated players; prediction API agreement, revision metadata, IDs, lines and slots. |
| E: HTTP read | COVERED | Manual submission → current-row verification → saved publication → lineup GET; published projected roster → GET with no imported report. |
| F: Side effects | COVERED | External HTTP prohibited; published snapshot reads dispatch no prediction/preparation job. |
| G: Frontend | Not applicable | No UI or JavaScript changes. |

## Requirements

1. SATISFIED in authored coverage: missing imported reports no longer hide the
   complete eligible lineup already stored with a prediction.
2. SATISFIED in authored coverage: all three partner lineup shapes use the same
   presentation behavior and include source revision metadata.
3. SATISFIED in authored coverage: prediction input readers do not consume saved
   forecast output; valid manual selection and stored evidence remain unchanged.
4. SATISFIED in authored coverage: failed/newer unavailable attempts cannot hide
   an existing successful snapshot, and identities remain scoped to game/team.
5. SATISFIED in authored coverage: live/final reads and injury exclusions retain
   their existing rules. Current starting-goalie selection is unchanged.
6. SATISFIED in authored coverage: older inconsistent presentation uses the same
   read normalization as the prediction API, never another game's roster.
7. SATISFIED in authored coverage: reads perform no forecast rebuild or report
   persistence; missing both report and prediction remains honestly unavailable.
8. SATISFIED by scope review: no migration, model-weight, queue routing, lineup
   admission, identity-resolution or prediction calculation change.

## Endpoint × verb authorization/scope

| Endpoint | Guest test | Wrong scope test | Authorized test |
| --- | --- | --- | --- |
| GET `/api/nhl/lineups/{id}` | requires authentication on every partner lineup read shape | requires stats read scope on every partner lineup read shape | restores STLs complete projected roster after prediction publication with no report |
| GET `/api/nhl/lineups?date=...` | same dataset | same dataset | provides the same saved participants on the date schedule endpoint |
| GET `/api/nhl-anticipated-lineups` | same dataset | same dataset | provides the same saved participants on the anticipated lineup endpoint |

These are global NHL reads, not organization/member-owned resources. Game/team
isolation maps to `does not borrow another games prediction`, `rejects mismatched
payload game identity`, and `rejects a snapshot assigned to a different team`.
Existing token revocation/authentication middleware remains unchanged.

## Frontend checklist

Not applicable: no components, DOM or frontend state changed.

## Test count and checks

`tests/Feature/NhlPredictionLineupReadTest.php`: **30 `it(...)` declarations**
plus dataset expansions for the three read shapes. Time is frozen, jobs are faked
and real provider HTTP is prohibited. Prediction fixtures publish through the
existing history writer; no model training or engine run is needed for reads.

Tests, CI, lint, builds and operational commands were **not run** under AGENTS.md.
Verification: source review and `git diff --check`. No migration required.
Suggested human-run regression command:

```sh
php artisan test tests/Feature/NhlPredictionLineupReadTest.php tests/Feature/NhlLineupInjurySafetyTest.php tests/Feature/NhlLineupSubmissionsApiTest.php
```

No production lineup, prediction or queue record was modified.
