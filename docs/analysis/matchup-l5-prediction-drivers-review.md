# Matchup L5 record and saved prediction drivers

Approved: items 1 and 2 only. Replace the visible Last 10 Games record with Last 5
Games and show relevant saved scoring drivers in Matchup. The proposed missing-xG
availability note was not approved and remains unimplemented for discussion.

## Files changed

- `app/Services/NhlPublicGamePage.php`
- `resources/js/pages/Games/Show.vue`
- `resources/js/pages/Games/game-panels.js`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/UI_DESIGN.md`
- `docs/ENUMS.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- This review document.

Earlier approved silent-feed changes remain uncommitted in several shared UI/docs
files; their scope is documented separately in `silent-background-feed-review.md`.
Unrelated import/schema/index work remains outside both changes.

## Behavior

Matchup renders L5 W-L-OTL instead of L10, with the existing actual-ending rules.
The shared form reader adds `l5_gp`, `l5_wins`, `l5_losses`, `l5_otl`; existing L10
API fields remain intact. Both windows retain the existing season/game-type and
strict prior-date scope. H2H continues to use its cross-season last-ten window.

A separate What drives this prediction comparison projects only small saved
revision fields: L5 wins/sample used, EV recent-form volume change, EV goals before
and after that change, lineup L20 PP goals/GP, L5 team PP goals allowed/GP, selected
PP history source, projected PP goals, SOG and total goals. Applied schedule
effects appear only for the frozen exact fourth-in-seven B2B rule; its home-win
probability increment is shown only when the saved result is inside probability
bounds, avoiding a claim of an unmeasured effect at a clamped endpoint.

The backend derives display effects from archived inputs/settings and saved scoring
totals. No engine forecast is run, history replayed or private bucket tree returned.
The displayed prediction revision pins Matchup and Factors requests; the driver
reader enforces same-game, available, canonical custom-engine evidence and matching
team identities. Missing/older inputs stay unknown and an invalid explicit revision
never selects another forecast. Browser allowlisting permits this new field only
in Matchup and cannot replace overview probabilities, scores or identity. The API
Matchup gains only the L5 form fields; this browser driver summary is separate.

No forecast calculation, probability, goalie rule, model/context record, scoring
coverage policy or xG placeholder changes. No migration or rebuild is required.

## Authored minimum coverage matrix

Written coverage below has not been executed.

| Behavior | Status | Named coverage |
|---|---|---|
| L5 record and retained L10 fields | COVERED | adds last five record while preserving API-compatible last ten fields; includes prior season form and the last ten cross-season meetings in API Matchup |
| Saved effects and source labels; no private evidence | COVERED | exposes saved Matchup drivers without raw algorithm definitions or bucket evidence |
| Current settings cannot alter archived driver effects | COVERED | exposes saved Matchup drivers without raw algorithm definitions or bucket evidence |
| Revision pinning and cross-game exclusion | COVERED | pins Matchup drivers to the displayed revision and rejects another games revision |
| Missing evidence stays unknown | COVERED | leaves missing historical driver inputs unknown rather than reading current settings |
| Applied schedule effects use frozen rules/settings | COVERED | reports only the exact fourth-in-seven B2B schedule effects from frozen settings |
| Browser table and absent summary | COVERED | shows saved recent form and PP drivers in Matchup without exposing equations; does not invent prediction drivers when no saved explanation exists |
| Browser request pin and allowlist | COVERED | pins deferred Matchup and Factors to the displayed prediction revision; existing Matchup allowlist test now asserts prediction_drivers isolation |

## Requirements checklist

1. SATISFIED in code: visible L5 record and retained compatible API L10 fields.
2. SATISFIED in code: actual saved forecast inputs/effects are shown in Matchup.
3. SATISFIED in code: pinned revision, redaction and unknown-value safeguards.
4. SATISFIED in scope: missing-xG discussion remains deferred without UI/scoring changes.
5. SATISFIED in written coverage/docs: behavior, API additive fields and browser contract recorded.

## Endpoint × verb authorization/scope

| Endpoint / verb | Case | Written coverage |
|---|---|---|
| `/games/{id}/details?section=matchup` GET | Guest and ordinary authenticated viewer | exposes saved Matchup drivers without raw algorithm definitions or bucket evidence (dataset) |
| Matchup GET | Same-game/revision scope | pins Matchup drivers to the displayed revision and rejects another games revision |
| Matchup GET | Date/team/type scope | existing season-form and last-ten cross-season H2H coverage plus adds last five record while preserving API-compatible last ten fields |
| `/api/nhl-game-predictions` GET | Valid partner scope and L5 read | includes prior season form and the last ten cross-season meetings in API Matchup |
| Prediction API GET | Guest/wrong-scope denial | requires API authentication for new engine predictions; requires the NHL statistics scope for new engine predictions |
| Organization/owner scope | Not applicable | Public descriptive reads; no lineup mutation authorization changes |

## Frontend component checklist

- Written assertions for Last 5 Games record, absence of the old visible L10 row,
  projected components, signed form effects and PP source labels.
- Driver container absent when no valid saved summary exists.
- Matchup requests pin the displayed revision; allowlists exclude drivers elsewhere.
- Existing local tab, no-reload and silent-feed tests remain applicable.
- Fixed fixtures, fake timers and mocked requests; no external network calls or
  new global application state.

## Per-file test declaration counts

- `tests/Feature/NhlPublicGamePageTest.php`: 97.
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 59.
- `resources/js/pages/games.test.js`: 127 (includes prior silent-feed additions).

Tests, CI, browser scripts, imports, rebuilds, migrations and queue operations were
not run. `git diff --check` passed; executed and browser verification remain for
human review.
