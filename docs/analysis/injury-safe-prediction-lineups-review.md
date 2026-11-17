# Injury-safe prediction lineups

## Files changed or created

- `app/Services/NhlLineupAvailability.php`
- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Http/Controllers/NhlGamesController.php`
- `app/Services/NhlAnticipatedLineupImporter.php`
- `app/Services/NhlAnticipatedLineupPayload.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlGameLineupProjectionBuilder.php`
- `app/Services/NhlGamePredictionHistory.php`
- `app/Services/NhlGamePredictionPayload.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `app/Services/NhlInjuryImporter.php`
- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlStartingGoalieSelector.php`
- `resources/js/pages/Games/Show.vue`
- `resources/js/pages/games.test.js`
- `tests/Feature/NhlLineupInjurySafetyTest.php`
- `docs/architecture/imports/NhlPlayerAvailability.yaml`
- `docs/architecture/imports/NhlAnticipatedLineups.yaml`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/injury-safe-prediction-lineups-review.md`

The shared eligibility service extends the existing availability abstraction; its
canonical rules live in `architecture/imports/NhlPlayerAvailability.yaml`.

## Minimum coverage matrix

COVERED means authored and source-reviewed, not executed or passing.
New backend cases live in `NhlLineupInjurySafetyTest.php`.

| Requirement | Coverage | Test names |
| --- | --- | --- |
| Partner rejects out skaters/goalies without writes | COVERED | rejects an out skater through the partner submission endpoint without saving observations; reports every unavailable submitted player by name and reason; rejects an unavailable submitted goalie as well as skaters; preserves the previous accepted lineup when a later submission is rejected |
| Healthy submission → DB → HTTP read | COVERED | accepts healthy replacements then reads exactly the accepted players through HTTP |
| UI shares eligibility and authorization | COVERED | applies the same injury refusal to a super-admin manual lineup submission; rejects an unavailable goalie picker selection before writing an observation; preserves guest and ordinary-member restrictions on manual lineup and goalie writes; permits an eligible super-admin goalie choice and exposes it through the read API |
| Questionable/suspected, scratch and return estimates | COVERED | keeps questionable unknown and suspected evidence eligible; allows an injured player to be listed as a scratch without dressing them; does not treat an estimated return date as medical clearance |
| Date cutoff and actual participants | COVERED | does not apply injury evidence first observed after the target cutoff; does not remove actual participants from a finished game; retains started-game official identity authority over current injuries |
| Stored lineup recheck and complete replacements | COVERED | hides a previously accepted anticipated lineup that now contains an unavailable player; does not let an older injury-incompatible manual observation outrank a healthy automated observation; resolves complete healthy fallback participants instead of dropping an injured player from the calculation; rejects an incomplete fallback instead of publishing a shortened roster; rejects an explicit roster override containing an unavailable player |
| Frozen selected identities | COVERED | keeps the selected snapshot identities equal to the players used for statistical inputs; reconstructs legacy API lineups only from the saved calculated roster and starter |
| Starter exclusions and recency | COVERED | uses the next eligible goalie observation when the newest starter is unavailable; rejects an explicit unavailable starter override |
| Stable batched preparation identity | COVERED | changes the preparation eligibility identity when a player becomes unavailable; does not invalidate eligibility for repeated heartbeat-only updates; restores eligibility after current injury state is cleared; keeps a shared date eligibility identity identical regardless of game iteration order |
| Unsafe saved forecast and retained history | COVERED | queues a replacement rather than returning an unsafe saved prediction as current; withholds a saved forecast when its calculated starting goalie is now out; continues serving a healthy saved forecast immediately without new work; does not apply present injuries to a historical saved forecast read |
| Material import refresh and scope | COVERED | refreshes only existing upcoming forecasts for changed teams; queues existing forecasts after a material injury import but not a repeated unchanged import |
| Public selected-revision consistency | COVERED | returns the exact frozen prediction lineup in the public Lineups panel; pins the Lineups panel to the displayed revision rather than a newer roster; withholds unsafe Factors without changing or deleting the saved revision |
| Partner authorization | COVERED | keeps guest and wrong-scope API access blocked (POST lineup, GET lineup and GET prediction cases) |
| Browser revision request contract | COVERED | existing games.test.js selected-revision preview test now checks Lineups alongside Matchup and Factors |

Existing regression suites remain relevant to unchanged identity, membership,
counts, duplicate, goalie-slot and unknown-peer rules. In particular,
`NhlLineupSubmissionsApiTest.php` retains cases including:

- uses reported skater slots rather than usual forward or defense position
- rejects recognized wrong-team players outside preseason including depth slots and goalies
- accepts recognized cross-team players in preseason without changing canonical assignments
- still rejects a goalie assigned to a skater slot
- accepts new submissions with unassigned canonical players
- continues accepting unknown players where the existing peer rules permit them
- full-name Will parser/API/manual submission coverage

## Numbered requirements checklist

1. SATISFIED: one supported-out game-date rule gates dressed manual/API players,
   automated candidates, current reads, fallback participants and starters.
2. SATISFIED: preserve identity/team/slot verification; reject incompatible
   submissions before saving, with names/reasons. Do not rewrite old observations.
3. SATISFIED: select a complete eligible fallback; never publish an injured or
   silently shortened lineup. Preserve actual started/finished NHL participants.
4. SATISFIED: new input teams archive plain-array lineup snapshots, including the
   calculated starter and healthy backup context. API `anticipated_lineups`, team
   calculated roster and public revision-pinned Lineups/Factors agree on identities.
5. SATISFIED: availability meaning enters date-shared and response fingerprints;
   heartbeat changes do not invalidate preparation. Material injury imports queue
   existing affected-forecast refreshes. Unsafe upcoming saved reads return the
   existing preparing response rather than a current pick; healthy and historical
   reads retain their immediate behavior.
6. SATISFIED: existing revisions remain immutable. Legacy presentation is derived
   only from that revision's calculated participants. No scoring equation, goalie
   edge formula, confidence formula, model rates, dependency or schema changes.
7. SATISFIED: partner API usage and canonical availability/prediction docs updated.
   No production data edits, imports, prediction reruns or worker operations done.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Denied cases | Allowed and injury-validation cases |
| --- | --- | --- |
| POST /api/nhl-lineups | keeps guest and wrong-scope API access blocked | accepts healthy replacements then reads exactly the accepted players through HTTP; rejects an out skater through the partner submission endpoint without saving observations |
| GET /api/nhl/lineups/{game} | keeps guest and wrong-scope API access blocked | accepts healthy replacements then reads exactly the accepted players through HTTP; hides a previously accepted anticipated lineup that now contains an unavailable player |
| GET /api/nhl-game-predictions | keeps guest and wrong-scope API access blocked | reconstructs legacy API lineups only from the saved calculated roster and starter; queues a replacement rather than returning an unsafe saved prediction as current |
| POST /games/{game}/lineup | preserves guest and ordinary-member restrictions on manual lineup and goalie writes | applies the same injury refusal to a super-admin manual lineup submission; existing full-name editor manual submission regression |
| POST /games/{game}/starting-goalie | preserves guest and ordinary-member restrictions on manual lineup and goalie writes | permits an eligible super-admin goalie choice and exposes it through the read API; rejects an unavailable goalie picker selection before writing an observation |

These are global NHL resources governed by existing API scopes or super-admin
permissions. No tenant/owner boundary, route name or authorization rule changes.

## Frontend component checklist

- Lineups requests include the displayed prediction revision ID, as Matchup and
  Factors already do; the existing deterministic request test now asserts all three.
- Existing loading, retry, cancellation and retained-content behavior is unchanged.
- No new controls, global state, timers, DOM rows or background status banners.
- Backend Lineups panel returns the pinned saved participants and calculated rows.

## Per-file test count and checks

- `tests/Feature/NhlLineupInjurySafetyTest.php`: 41 `it(...)` declarations, plus
  parameterized evidence and authorization cases.
- `resources/js/pages/games.test.js`: 164 `it`/`it.each` declarations; the existing
  selected-revision request test was extended rather than duplicated.

Performed: source/diff review and `git diff --check`.
Not performed: tests, CI, builds, lint, browser checks, migrations, imports,
season syncs, scheduler/queue commands or production prediction reruns.
No migration required. No production lineup, injury or revision rows were edited.
After deployment, an API request for an injury-incompatible upcoming saved
forecast queues its corrected calculation and returns the normal preparing status.


## Follow-up: current injuries alongside frozen lineups

Changed only `app/Services/NhlPublicGamePage.php`,
`tests/Feature/NhlLineupInjurySafetyTest.php`,
`docs/architecture/ui/NhlPublicGamePage.yaml` and this review document.

The saved-prediction Lineups branch now attaches current team injuries using the
existing `NhlAvailabilityPayload`. Injury lists remain independent of the frozen
calculated participants and do not modify the saved revision or engine inputs.
No new endpoint, authorization, frontend component or schema changes.

| Contract | Authored coverage | Test name |
| --- | --- | --- |
| Saved revision → HTTP read includes excluded players' injuries and retains the stored payload | COVERED | includes current excluded players in Injuries beside the saved prediction lineup through HTTP |
| Healthy teams return explicit empty collections | COVERED | returns explicit empty injury lists for both healthy teams in the saved-lineup response |
| Correct side and current availability independent of frozen participants | COVERED | keeps each teams current injuries separate from its frozen prediction participants |

1. SATISFIED: attach each team's existing current injury payload to the saved-lineup response.
2. SATISFIED: retain frozen participants, calculations and immutable revisions.
3. SATISFIED: author HTTP regression cases for populated, empty and team-specific lists.

The endpoint is the existing public GET /games/{game}/details; the three new HTTP
cases cover allowed guest reads. There is no permission, tenant or owner change.
Frontend changes: none. The full test file now has 41 `it(...)` declarations.
Checks: source review and `git diff --check`; tests/CI/builds not run.
No migration required. No production operations performed. Commit and push were
authorized separately after the implementation review.
