---
pr_id: 14
pr_name: pr14
status: Archived
created: 2026-07-15
last_updated: 2026-07-16
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
- `app/Services/ProspectEligibilityService.php`
- `app/Services/ImportNHLPlayer.php`
- `app/Console/Commands/RefreshNhlProspectFlagsCommand.php`
- `app/Http/Controllers/LeagueController.php`
- `app/Http/Controllers/StatsController.php`
- `app/Support/FantraxViewerScope.php`
- `app/Support/Stats/LeagueStatsOwnershipHydrator.php`
- `app/Support/Stats/StatsPayloadBuilder.php`
- `app/Support/Stats/StatsQueryFilterApplier.php`
- `app/Http/Controllers/CommunityLeagues.php`
- `app/Models/NhleLeagueFactor.php`
- `app/Support/Stats/NhleLeagueFactorResolver.php`
- `app/Support/Stats/NhleProspectLens.php`
- `app/Console/Commands/AuditNhleLeagueMappingsCommand.php`
- `database/migrations/*_create_nhle_league_factors_table.php`
- `database/seeders/NhleLeagueFactorSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `resources/views/leagues/_panel.blade.php`
- `resources/js/pages/stats-payload-client.js`
- `resources/js/pages/stats-page.js`
- `resources/js/components/StatsPage/stats-desktop.js`
- `resources/js/pages/stats-page.test.js`
- `tests/Feature/FantraxDraftingWindowTest.php`
- `tests/Feature/StatsPayloadPipelineTest.php`

## Changed Documentation

- `docs/ENUMS.md`
- `docs/architecture/integrations/FantraxLeagueSync.yaml`
- `docs/architecture/integrations/FantraxDraftStateSync.yaml`
- `docs/architecture/integrations/PlatformLeagueRosterSlots.yaml`
- `docs/architecture/application/DraftCentralDraftModel.yaml`
- `docs/architecture/imports/ProspectEligibility.yaml`
- `docs/architecture/stats/PerspectiveDrivenStatsPayload.yaml`
- `docs/architecture/stats/NhleLeagueFactors.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/DB_SCHEMA.md`
- `docs/import-templates/nhle-league-factors-2026.md`

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

For division-scoped Fantrax leagues, `/leagues/*` user-facing reads now default to the viewer's own Fantrax division or pool, including commissioner users. All-division views belong in a separate explicit management URI.

League stats payload ownership hydration now uses the same viewer Fantrax division scope, so League/Players fantasy-team dropdowns are derived only from the viewer's own division or pool.

League stats now also support a `Teams` tab next to `Players`. The tab reuses the league stats payload endpoint with `resource=teams`, aggregates stat columns across each scoped fantasy team's non-minor pro players, keeps rate-style stats as averages, and exposes an `Averages` toggle for average-per-player display. The Teams table omits player-only fields such as NHL team, age, roster slot, and contract term, while preserving Cap.

Draft Central player search now includes a synthetic `{latest draft year} Entry Draft` perspective alongside Prospects, Prospects - Goalies, and My Queue. The entry-draft view reuses the prospect stats payload with an `entry_draft_year` filter and still intersects against the league's available-player universe, so it only lists available players from the most recently imported NHL entry draft class.

### NHLe Factor Reference Data

The PR now includes a versioned `nhle_league_factors` table, `NhleLeagueFactor` model, and deterministic seeder for the manually transcribed 2026 NL Ice Data / Thibaud Chatel NHLe table.

League/Players prospect views now expose an opt-in `NHLe` button beside `Ranks`. When enabled, the league payload applies the latest seeded NL Ice Data points factor to skater prospect production fields for rows whose prospect league resolves through `source_league_name` or explicit `mapped_league_codes`. The lens leaves raw games played, identity, roster, contract, and cap fields unchanged.

NHLe league recognition is now centralized in `NhleLeagueFactorResolver`, and `stats:nhle-audit` reports which imported `stats.league_abbrev` values are matched or unmapped. Generic `NCAA` explicitly resolves to the source `Independent` NCAA row as a temporary runtime alias until conference-specific NCAA mapping is available. Known spelling variants such as `HockeyAllsvenskan`, `Czechia2`, `Czechia U20`, `U20 Nationell`, and `U20 SM-sarja` are repaired by runtime aliases. Truly unknown leagues resolve to the lowest seeded points factor as a conservative fallback while still being logged for review.

Prospect stats payloads now treat `stats.is_prospect` as the prospect row-universe authority. They no longer require `players.is_prospect` to be true for a legacy stats row to appear.

`ProspectEligibilityService` now defines DynastyIQ prospect flags from local data after NHL landing stats are imported. A player is a prospect only when they have NHL draft evidence, are younger than 26 on the September 15 evaluation cutoff, have no more than 25 total NHL regular-season games played in legacy stats, and have a non-NHL legacy stats row with games played in the current evaluation season window. `ImportNHLPlayer` recomputes and persists both `players.is_prospect` and that player's `stats.is_prospect` after importing landing season totals.

The `nhl:isprospects` command provides an operator-triggered backfill path that iterates NHL-linked players, reuses `ProspectEligibilityService`, and rewrites both `players.is_prospect` and linked `stats.is_prospect` values.

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
- League stats ownership hydration scoped to the viewer's Fantrax division.
- League team aggregate payload rows across pro roster players.
- League Teams tab controls and average display behavior.

## Verification Required

Codex performed syntax checks only:

- `php -l app/Services/SyncFantraxLeague.php`
- `php -l app/Services/SyncFantraxDraftState.php`
- `php -l app/Http/Controllers/LeagueController.php`
- `php -l app/Http/Controllers/StatsController.php`
- `php -l app/Support/FantraxViewerScope.php`
- `php -l app/Support/Stats/LeagueStatsOwnershipHydrator.php`
- `php -l app/Http/Controllers/CommunityLeagues.php`
- `php -l app/Services/ProspectEligibilityService.php`
- `php -l app/Services/ImportNHLPlayer.php`
- `php -l app/Console/Commands/RefreshNhlProspectFlagsCommand.php`
- `php -l app/Support/Stats/NhleProspectLens.php`
- `php -l app/Support/Stats/NhleLeagueFactorResolver.php`
- `php -l app/Console/Commands/AuditNhleLeagueMappingsCommand.php`
- `php -l database/seeders/NhleLeagueFactorSeeder.php`
- `php -l tests/Feature/FantraxDraftingWindowTest.php`
- `php -l tests/Feature/StatsPayloadPipelineTest.php`
- JS syntax checks for the touched stats page modules.

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
- Fantrax logo work remains tabled. The brittle `fantrax:inspect-logos` feature tests were removed from this PR because Fantrax logo support is not active scope.

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
