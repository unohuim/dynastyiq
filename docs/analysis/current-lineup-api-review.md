# Current lineup and saved prediction API review

Approved scope: read current lineups independently of saved predictions, persist
internally generated participants, retain manual authority and active-run write
exclusion, and refresh predictions only when effective lineup assignments change.
No prediction formula changes, migration, production data repair or worker action.

## Files changed

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Models/NhlCurrentLineup.php`
- `app/Models/NhlLineupObservation.php`
- `app/Services/NhlAnticipatedLineupImporter.php`
- `app/Services/NhlAnticipatedLineupPayload.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `tests/Feature/NhlPredictionLineupReadTest.php`
- `tests/Feature/NhlManualLineupPredictionRefreshTest.php`
- `tests/Feature/NhlLineupInjurySafetyTest.php`
- `docs/architecture/imports/NhlAnticipatedLineups.yaml`
- `docs/architecture/imports/NhlPlayerAvailability.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review.

## Minimum coverage matrix

COVERED means assertions are authored, not that tests were executed.

| Category | Status | Evidence |
| --- | --- | --- |
| A: Validation/auth | COVERED | Existing API/UI authorization, active-run 409, complete roster, duplicate, unknown, goalie, injury and team-membership tests retained. New saved-read auth cases. |
| B: Selection | COVERED | Current manual priority, per-team saved fallback with no current rows, generated current selection, unchanged and changed assignments. |
| C: Persistence | COVERED | Generated publish -> observation/current assertions -> HTTP read; immutable originals and saved revisions retained, no duplicate generated observations or invented goalie reports. |
| D: API | COVERED | Current anticipated_lineups alongside frozen prediction_lineups, original revision ID, pending flags and unchanged predicted scores. |
| E: HTTP | COVERED | Partner manual submit -> persisted current selection -> schedule and prediction reads; generated publish -> lineup GET. |
| F: Side effects | COVERED | Changed save queues after commit; identical save does not; active submissions reject; generated output cannot self-trigger; reads never generate participants. |
| G: Frontend | Not applicable | No frontend files changed. |

## Requirements checklist

1. SATISFIED in code and authored coverage: serialized arrays merge in a base
   collection, fixing the empty-model-collection getKey crash.
2. SATISFIED in code and authored coverage: current complete eligible lineup wins;
   a saved prediction supplies only a missing team.
3. SATISFIED in code and authored coverage: preparation publishes generated
   participants to existing shared tables, marked projected and below valid
   manual/official/reported evidence. Cached prepared inputs also publish when
   used for a calculation. Historical replay and private overrides do not publish.
4. SATISFIED in code and authored coverage: saved forecasts remain immediately
   available with frozen participants and timestamps; lineup/injury refresh is
   deferred until after the response and failures are reported separately.
5. SATISFIED in code and authored coverage: player/assignment changes refresh after
   commit; repeated identical submissions retain evidence without another refresh.
6. SATISFIED in code and authored coverage: existing user/API mid-run 409 admission
   remains. Internal publication uses the game-row lock and never self-triggers.
7. SATISFIED by source review: internal generated output is excluded from both
   preparation and response-cache source fingerprints, and never qualifies as
   completed report discovery or external corroboration.
8. SATISFIED by scope review: no schema, scoring, dependency, worker or queue-routing
   change. Existing lineups queue and prediction pipeline are reused.

## Endpoint × verb authorization/scope matrix

| Endpoint | Guest | Wrong permission/scope | Authorized |
| --- | --- | --- | --- |
| GET /api/nhl/lineups/{id} | requires authentication on every partner lineup read shape | requires stats read scope on every partner lineup read shape | publishes generated participants to the shared current store before prediction publication |
| GET /api/nhl/lineups | same dataset | same dataset | provides the same saved participants on the date schedule endpoint |
| GET /api/nhl-anticipated-lineups | same dataset | same dataset | provides the same saved participants on the anticipated lineup endpoint |
| GET /api/nhl-game-predictions | requires authentication when returning a saved forecast with current lineups | requires stats read scope when returning a saved forecast with current lineups | preserves the prediction snapshot separately from current API lineups |
| POST /api/nhl-lineups | does not queue anything when the partner omits authentication | does not queue anything when the partner lacks lineup write scope | queues a prediction after an authorized partner lineup has committed |
| POST /games/{id}/lineup | does not queue anything for a guest game page submission | does not queue anything for a game page member without super admin permission | queues a prediction after a super admin game page lineup has committed |

These are global NHL resources, not organization/member-owned records. Game/team
isolation remains covered by missing game, other-team, mismatched snapshot identity
and other-game saved prediction cases. Internal publication is a server-only
service call; public request flags cannot opt out of the active-prediction lock.

## Frontend checklist

Not applicable: no Vue/DOM, browser state, navigation or animation changes.

## Test counts and validation

| File | it(...) declarations |
| --- | ---: |
| tests/Feature/NhlPredictionLineupReadTest.php | 54 |
| tests/Feature/NhlManualLineupPredictionRefreshTest.php | 32 |
| tests/Feature/NhlLineupInjurySafetyTest.php | 65 |

Time is frozen; provider HTTP is prohibited and jobs are faked. Test source was
reviewed; `git diff --check` was run. Tests, lint, CI, builds, imports and operational
commands were not run under AGENTS.md. Human execution remains outstanding.

Suggested human-run regression command:

```sh
php artisan test tests/Feature/NhlPredictionLineupReadTest.php tests/Feature/NhlManualLineupPredictionRefreshTest.php tests/Feature/NhlLineupInjurySafetyTest.php tests/Feature/NhlLineupSubmissionsApiTest.php tests/Feature/NhlCustomGamePredictionTest.php
```

No migration required. Existing invalid current rosters still need normal
prediction preparation to publish a healthy replacement; reads never synthesize
one synchronously. If neither current nor saved lineup is usable, that team's
lineup stays unavailable until preparation succeeds; its saved forecast and the
other team's eligible lineup remain available.
