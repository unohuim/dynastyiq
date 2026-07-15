---
pr_id: 14
pr_name: pr14
status: Active
created: 2026-07-15
last_updated: 2026-07-15
---

# Fantrax League Shape And Draft Scope

## Source

Fantrax response documentation work compared real `getLeagueInfo`, `getTeamRosters`, `getDraftPicks`, `getDraftResults`, and `getPlayerIds` payloads across:

- Super Duper League.
- Champions League of Hockey.
- FHL Tiered Dynasty.

That review showed DynastyIQ has enough provider data to represent very different Fantrax league shapes, but current sync code only persists part of that meaning.

## Objective

Persist and expose Fantrax league shape so users, managers, and commissioners see the league they actually play in:

- Standard single-pool leagues.
- Multi-division leagues without duplicate players.
- Division-scoped duplicate-player leagues.
- Flat drafts.
- Division-scoped drafts.
- Salary-cap leagues with or without custom Fantrax salary/contract metadata.

The first implementation should prioritize correctness for FHL-style division-scoped drafts and player pools.

## Changed Code Paths

- `app/Services/SyncFantraxLeague.php`
- `app/Services/SyncFantraxDraftState.php`
- `app/Http/Controllers/LeagueController.php`
- `app/Http/Controllers/CommunityLeagues.php`
- `resources/views/leagues/_panel.blade.php`
- `tests/Feature/FantraxDraftingWindowTest.php`

## Changed Documentation

- `docs/ENUMS.md`
- `docs/architecture/integrations/FantraxLeagueSync.yaml`
- `docs/architecture/integrations/FantraxDraftStateSync.yaml`
- `docs/architecture/integrations/PlatformLeagueRosterSlots.yaml`
- `docs/architecture/application/DraftCentralDraftModel.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`

## Implemented

### League Shape Persistence

Fantrax league sync now normalizes provider league shape into `platform_leagues.settings.league_shape`:

- `duplicate_player_type`
- `player_pool_scope`
- `team_count`
- `division_count`
- `divisions`
- `team_divisions`
- `scoring_type`
- `roster_period_count`
- `scoring_period_count`
- `custom_salary_detected`
- `contract_codes_detected`
- `draft_shape`

No schema change was added. The current implementation intentionally uses existing settings JSON and existing roster slot tables.

### Team Division Context

Fantrax team sync now preserves division/pool metadata in `platform_teams.extras.fantrax` when present in `getLeagueInfo`.

### Player Pool And Eligibility

Fantrax player eligibility parsing now supports both:

- flat `playerInfo.{fantraxId}`
- division-grouped `playerInfo.{division}.{fantraxId}`

Rules implemented:

- `duplicatePlayerType: NONE` keeps player pool scope as league-wide even when divisions exist.
- `duplicatePlayerType: ACROSS_DIVISIONS` marks player pool and draft scope as division-scoped.
- Roster display can use flattened eligibility, while league shape preserves pool scope.

### Draft Scope

Fantrax draft sync now:

- preserves `division` from draft result rows in the raw payload and LeagueController row payload.
- includes `division` in `provider_pick_key` when present.
- avoids global `overall_pick` for division-scoped rows where the provider pick number repeats by division.
- preserves flat or division-keyed `draftOrder` in `drafts.settings.provider_draft_order`.
- stores provider draft state in `drafts.settings.provider_draft_state`.
- marks rows without a provider player id as pending.
- emits pick-made events only when a row transitions from no provider player id to a provider player id.
- uses provider pick key as the final pending-slot ordering tie-breaker so division-scoped repeated pick numbers remain deterministic.

### Roster Slot Settings

Fantrax league sync now upserts `platform_league_roster_slots` from `getLeagueInfo.rosterInfo.positionConstraints`.

### UI Payload

League and community pages now receive compact league-shape payloads. The league panel displays small structure badges for:

- player pool scope.
- draft shape.
- division count.
- Fantrax salary source.

Draft Central backend payload now includes `division` and stable ordering for rows where `overall_pick` is null.

For division-scoped Fantrax leagues, normal user-facing league reads now default to the viewer's own Fantrax division or pool. Commissioner/admin reads retain all-division visibility.

## Tests Added

Focused Pest coverage was added in `tests/Feature/FantraxDraftingWindowTest.php` for:

- `duplicatePlayerType: NONE` with divisions remaining league-scoped.
- division-grouped `playerInfo` eligibility in duplicate-player leagues.
- Fantrax roster slot settings upserted from `rosterInfo.positionConstraints`.
- division-scoped draft rows generating unique provider pick keys.
- division-keyed `draftOrder` preserved.
- pending FHL-style draft slots without provider player ids staying pending and not dispatching pick events.
- picked division-scoped slots dispatching when a provider player id appears.
- Draft Central rows scoped to the viewer's Fantrax division.
- league team and roster payload rows scoped to the viewer's Fantrax division.

## Verification Required

Codex performed syntax checks only:

- `php -l app/Services/SyncFantraxLeague.php`
- `php -l app/Services/SyncFantraxDraftState.php`
- `php -l app/Http/Controllers/LeagueController.php`
- `php -l app/Http/Controllers/CommunityLeagues.php`
- `php -l tests/Feature/FantraxDraftingWindowTest.php`

Human-run verification still required:

- Run the targeted Pest file and any broader suite the human wants.
- Run real Fantrax sync checks for SDL, CLH, and FHL-style leagues.
- Confirm `platform_leagues.settings.league_shape` after sync.
- Confirm `drafts.settings.provider_draft_order` for flat and division-keyed drafts.
- Confirm FHL-style pending draft slots do not create pick-made events.
- Confirm drafted rows transition from pending to picked when provider player ids appear.

## Outstanding Decisions

- Decide whether PR14 should visibly group/label Draft Central rows by division. Backend payload support exists, but the visible UI grouping is not implemented.
- Decide whether Fantrax response documentation/sample files belong in PR14 or should remain separate documentation work.

## Suggested Human Test Commands

Run only if you want to verify this PR locally:

```bash
php artisan test tests/Feature/FantraxDraftingWindowTest.php
```

Broader test commands should be chosen by the human based on PR scope.

## Out Of Scope

- Rebuilding Draft Central UI from scratch.
- Implementing future draft asset persistence.
- Building provider-earned Fantrax fantasy stat totals.
- Changing NHL game/stat import logic.
- Direct production data repair.
- Running Fantrax imports or sync jobs.
