# Prediction lineup exclusion and context reuse

Approved scope: reuse unchanged pregame history across lineup changes, add only
missing participant context, and reject manual/API and automated lineup or
starter writes while predictions for that game are active. No scoring equation
changes, migrations, production operations or historical bucket-preparation
optimization are included.

## Implementation

- `NhlCustomGameForecast` separates historical source identity from selected
  participants. Existing source/model/schedule corrections still invalidate
  history. Old participant hashes are verified against their owning run's frozen
  participants and the current source identity before reuse.
- `NhlPregameContextBuilder` keeps reusable teams, existing player rows and
  comparisons untouched. Missing selected players/starters are added at the
  common original cutoff and retain their own comparison completion evidence.
  Unselected saved participants remain available for later roster changes.
- `NhlGame` serializes lineup writes and prediction admission on the game's
  existing database row. Manual prediction items use their persisted active
  states; API builds and refresh variants use independently owned expiring
  activity in the existing shared cache. Synchronous activity expires within
  the ten-minute build-lock window; refresh activity expires within the existing
  one-day retry deadline. Normal success/failure releases it sooner.
- UI/partner writes, targeted refresh admission, automated lineup reconciliation
  and persistence, and RotoWire goalie writes return a conflict without accepting
  that update. Official NHL live starter locks remain authoritative.
- `NhlGamePredictionResponseCache` preserves refresh protection across preparing
  releases and retryable exceptions, clearing it on successful refresh or the
  existing terminal failure callback. Overlapping variants retain separate owners.
- Admin admission locks game rows in sorted order before inserting queued items.
- The input pipeline version changes; the game scoring algorithm does not.

The separate raw Train bucket collection, defense fitting and historical bucket
preparation still run when their prepared input cache is invalid. This change
does not claim to remove that remaining cost or establish a runtime improvement.

## Files

Code: `app/Models/NhlGame.php`, `app/Services/NhlCustomGameForecast.php`,
`app/Services/NhlPregameContextBuilder.php`,
`app/Services/NhlGamePredictionResponseCache.php`,
`app/Services/NhlAnticipatedLineupImporter.php`,
`app/Services/NhlStartingGoalieImporter.php`,
`app/Http/Controllers/Admin/NhlGamePredictionController.php`,
`app/Http/Controllers/Api/NhlLineupSubmissionsController.php`,
`app/Http/Controllers/NhlGamesController.php`, `config/nhl-custom-prediction.php`.

Canonical rules: `docs/architecture/stats/NhlCustomGamePrediction.yaml`,
`docs/architecture/stats/NhlGamePredictionHistory.yaml`,
`docs/architecture/stats/NhlPregameContextEvidence.yaml`,
`docs/architecture/imports/NhlAnticipatedLineups.yaml`,
`docs/architecture/imports/NhlPlayerAvailability.yaml`, `docs/ENUMS.md`.
Consumer documentation: `docs/integrations/DIQ-API-Usage-Doc.md`.
Derived inventory: `docs/ARCHITECTURE_INVENTORY.md` (prediction section only).

## Authored coverage matrix

All entries describe written regression coverage, not executed results.

| Contract | Coverage | Named case |
|---|---|---|
| Validation/conflict response | COVERED | rejects partner lineup changes and preserves the current lineup during active work |
| Retain saved inputs and source corrections | COVERED | reuses committed history when only line assignments change; rejects context reuse when historical result values change |
| Missing participant persistence | COVERED | adds only missing participants and leaves existing context rows untouched |
| Legacy snapshot compatibility | COVERED | verifies legacy participant hashes against unchanged source data before reuse |
| Public read after rejected write | COVERED | rejects partner lineup changes and preserves the current lineup during active work |
| API preparation lifecycle | COVERED | keeps pending API input admission across retries then releases after successful refresh |
| Failure lifecycle | COVERED | releases synchronous input admission after a terminal calculation exception; keeps refresh protection after retryable errors until terminal cleanup |
| Other games and overlapping owners | COVERED | does not block one game because another game has an active prediction; keeps overlapping API owners protected until the final owner releases |
| Automated providers | COVERED | blocks automated lineup reconciliation before fetching provider data; rejects automated goalie reports until active prediction work finishes |
| Frontend state | N/A | Existing error renderers already display the server's message; no browser state changes |

## Requirements checklist

1. SAT scoring and forecast equations unchanged: SATISFIED in implementation.
2. Historical validity separate from current lineup selection: SATISFIED.
3. Reuse committed history; add only missing selected participants: SATISFIED.
4. Block manual/API and automated lineup/starter writes during active prediction:
   SATISFIED for operational API/admin admission and persistence paths.
5. Atomic writer/admission check: SATISFIED by shared game-row transactions;
   cross-process PostgreSQL concurrency execution remains for human verification.
6. Existing live NHL starter authority and authorization retained: SATISFIED.
7. No migration, new table, dependency, production interruption or test execution:
   SATISFIED.

## Endpoint and authorization scope

| Endpoint/verb | Scope | Named coverage |
|---|---|---|
| POST `/api/nhl-lineups` | Write-scoped partner allowed normally; active game returns 409, evidence/read unchanged | rejects partner lineup changes and preserves the current lineup during active work; existing partner credential/scope tests |
| POST `/games/{game}/lineup` | Guest 401, ordinary user 403, super admin 409 during active work | blocks first party manual submissions while preserving their authorization checks |
| POST `/games/{game}/starting-goalie` | Existing guest/non-admin denials; super admin conflict while active | existing protects both picker endpoints; rejects starter edits and targeted lineup refreshes during API prediction preparation |
| POST `/games/{game}/lineup/refresh` | Existing super-admin authority; active-game conflict before dispatch | rejects starter edits and targeted lineup refreshes during API prediction preparation; existing targeted-refresh auth cases |
| POST `/admin/game-predictions/runs` | Existing super-admin/date validation and persisted queued admission | existing blocks guests on every operational endpoint; blocks ordinary users on every operational endpoint; admits one date and persists the requesting admin and stack |

These are global NHL resources; no organization ownership scope is introduced.
No Vue changes: existing manual-lineup, goalie-picker and refresh error displays
consume the returned server message.

## Test files and counts

| Full existing test file | it declarations after additions |
|---|---:|
| `tests/Feature/NhlAvailabilityApiTest.php` | 182 |
| `tests/Feature/NhlCustomGamePredictionTest.php` | 109 |
| `tests/Feature/NhlLineupSubmissionsApiTest.php` | 55 |
| `tests/Feature/NhlOperationalGamePredictionsTest.php` | 47 |

Datasets expand these declarations. No new global test functions were added;
fixtures use existing per-test closures, fixed clocks, faked providers/queues and
the array cache. Full test suites remain intact in their files.

Only `git diff --check` was executed. No CI/tests, imports, migrations, workers
or production writes were run. Human verification command:

```sh
php artisan test tests/Feature/NhlAvailabilityApiTest.php tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlLineupSubmissionsApiTest.php tests/Feature/NhlOperationalGamePredictionsTest.php
```
