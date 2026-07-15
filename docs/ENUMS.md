# ENUMS - Canonical Enum Authority

This document defines the canonical, normative enum-like values used throughout the system.
It is the source of truth for all domain-level enum-like values referenced in database schemas, models, services, controllers, seeders, and tests.

Do not introduce new enum values without updating this document.

---

## Change Discipline

- New enum values MUST be added here before use.
- Database enum constraints and request validation rules MUST match this document.
- Tests MUST only use values defined here.
- Removing or renaming a value requires:
    - Migration
    - Backfill or data safety plan
    - Update to this document

---

## Notes

- Some values are enforced by database `enum` columns.
- Some values are string-backed and enforced only by validation, service logic, seeders, or conventions.
- String-backed values documented here are still canonical.
- Migrations remain the schema source of truth; this file is the domain-value source of truth.

---

## Analytics

### Analytics Identity Link Method

**Name:** Analytics identity link method
**Storage location(s):** `analytics_identity_links.method` (string column)
**Allowed values currently emitted:**

- `authenticated_request`

**Semantic meaning:**

- `authenticated_request`: An app-owned anonymous visitor cookie was observed on a request made by an authenticated user, allowing prior anonymous analytics rows to be linked to that user.

---

## Authorization

### Role Scope

**Name:** Role scope
**Storage location(s):** `roles.scope` (enum column)
**Allowed values:**

- `global`
- `organization`

**Semantic meaning:**

- `global`: Role applies platform-wide and is not scoped to a specific organization.
- `organization`: Role applies within one organization through `role_user.organization_id`.

**Notes:**

- Default value is `organization`.
- Global assignments use `role_user.organization_id = NULL`.

### Seeded Role Slug

**Name:** Seeded role slug
**Storage location(s):** `roles.slug` (unique string column, seeded by `RoleSeeder`)
**Allowed values:**

- `super-admin`
- `admin`
- `manager`
- `scout`
- `commissioner`
- `creator`

**Semantic meaning:**

- `super-admin`: Global platform administrator.
- `admin`: Organization administrator.
- `manager`: Organization manager.
- `scout`: Organization scout.
- `commissioner`: Fantasy league/community commissioner.
- `creator`: Creator/community operator role.

**Notes:**

- `roles.slug` is not a database enum, but seeded slugs are canonical.
- `super-admin` is the only seeded global role.

---

### League User Role

**Name:** League user role
**Storage location(s):** `league_user_roles.role` (string column)
**Allowed values currently emitted:**

- `commissioner`
- `co_commissioner`

**Semantic meaning:**

- `commissioner`: User can manage the specific league.
- `co_commissioner`: User can manage the specific league as a delegated commissioner.

**Notes:**

- The column is string-backed and not database constrained.
- Organization-scoped `commissioner` roles do not imply league-scoped commissioner rights.

---

## Hockey Domain

### Player Shoots

**Name:** Player handedness / shoots
**Storage location(s):** `players.shoots` (nullable enum column)
**Allowed values:**

- `R`
- `L`

**Semantic meaning:**

- `R`: Right shot.
- `L`: Left shot.

### Player Status

**Name:** Player status
**Storage location(s):** `players.status` (string column)
**Allowed values currently used:**

- `active`

**Semantic meaning:**

- `active`: Player is active in the application player pool.

**Notes:**

- Migration comments mention possible values such as `free_agent` and `retired`, but the column is not constrained and code currently defaults to `active`.
- Add any additional player lifecycle values here before using them.

### NHL Unit Type

**Name:** NHL unit type
**Storage location(s):** `nhl_units.unit_type` (enum column)
**Allowed values:**

- `F`
- `D`
- `G`
- `PP`
- `PK`

**Semantic meaning:**

- `F`: Forward unit/line.
- `D`: Defense unit/pair.
- `G`: Goalie unit.
- `PP`: Power-play unit.
- `PK`: Penalty-kill unit.

### NHL Zone Code

**Name:** NHL shift zone code
**Storage location(s):** `nhl_unit_shifts.starting_zone`, `nhl_unit_shifts.ending_zone` (nullable enum columns)
**Allowed values:**

- `O`
- `N`
- `D`

**Semantic meaning:**

- `O`: Offensive zone.
- `N`: Neutral zone.
- `D`: Defensive zone.

### NHL Strength State

**Name:** NHL strength state
**Storage location(s):** `play_by_plays.strength`, `nhl_unit_game_strength_summaries.strength`, `nhl_player_game_strength_summaries.strength`, stats filters
**Allowed values:**

- `EV`
- `PP`
- `PK`

**Semantic meaning:**

- `EV`: Even-strength context, including current empty-net 6v5 or 5v6 contexts.
- `PP`: Power-play context for the advantaged team.
- `PK`: Penalty-kill context for the shorthanded team.

**Notes:**

- Empty-net context is not a separate strength value in the current model.
- Add a separate context flag before splitting 6v5 or 5v6 away from `EV`.

### NHL Penalty Type Code

**Name:** NHL penalty type code
**Storage location(s):** `play_by_plays.penalty_type_code`
**Allowed values currently handled by domain logic:**

- `MAT`

**Semantic meaning:**

- `MAT`: Match penalty. NHL play-by-play stores `duration` as `5`; summary and unit PIM calculations add the automatic 10-minute misconduct for 15 total penalty minutes.

**Notes:**

- Other provider penalty type codes may be stored, but only `MAT` has special calculation behavior documented here.

### NHL Game Type

**Name:** NHL game type
**Storage location(s):** `nhl_games.game_type`, `nhl_import_progress.game_type`, `nhl_season_stats.game_type`, stats request validation
**Allowed values in UI/API filters:**

- `1`
- `2`
- `3`

**Semantic meaning:**

- `1`: Preseason.
- `2`: Regular season.
- `3`: Playoffs.

**Notes:**

- `nhl_games.game_type` is an integer and is not database constrained.
- Stats controllers validate `game_type` as `1`, `2`, or `3`.
- `2` is the default regular-season value in stats views.

### NHL Goalie Decision

**Name:** NHL goalie decision
**Storage location(s):** `nhl_game_summaries.goalie_decision`
**Allowed values:**

- `W`
- `OTW`
- `SOW`
- `L`
- `OTL`
- `SOL`
- `ND`

**Semantic meaning:**

- `W`: Goalie credited with a regulation win by DynastyIQ native goalie summary logic.
- `OTW`: Goalie credited with an overtime win by DynastyIQ native goalie summary logic.
- `SOW`: Goalie credited with a shootout win by DynastyIQ native goalie summary logic.
- `L`: Goalie credited with a regulation loss by DynastyIQ native goalie summary logic.
- `OTL`: Goalie credited with an overtime loss by DynastyIQ native goalie summary logic.
- `SOL`: Goalie credited with a shootout loss by DynastyIQ native goalie summary logic.
- `ND`: Goalie appeared without receiving the game decision.

**Notes:**

- The value is derived from imported NHL game and boxscore context for native fantasy summaries.
- External provider goalie-decision values must not be stored here unless they are explicitly mapped to this enum.

---

## NHL Import Pipeline

### NHL Import Type

**Name:** NHL import type
**Storage location(s):** `nhl_import_progress.import_type` (enum column)
**Allowed values:**

- `pbp`
- `summary`
- `boxscore`
- `shifts`
- `shift-units`
- `connect-events`
- `sum-game-units`
- `validate-summary`

**Semantic meaning:**

- `pbp`: Import play-by-play events.
- `summary`: Summarize play-by-play into per-player game summaries.
- `boxscore`: Import NHL boxscore data.
- `shifts`: Import raw NHL shift data after boxscore targets are available.
- `shift-units`: Build NHL unit shift windows.
- `connect-events`: Link play-by-play events to unit shifts.
- `sum-game-units`: Aggregate per-game unit summaries.
- `validate-summary`: Compare computed game summaries to official boxscore totals.

**Notes:**

- `NhlImportStages` defines the canonical stage order, dependencies, job mappings, and stale-timeout config keys.
- `NhlImportOrchestrator` advances stages using `NhlImportStages`.

### NHL Import Status

**Name:** NHL import status
**Storage location(s):** `nhl_import_progress.status` (enum column)
**Allowed values:**

- `scheduled`
- `running`
- `error`
- `completed`
- `skipped`

**Semantic meaning:**

- `scheduled`: Import stage is queued for future work.
- `running`: Import stage has been claimed by a worker.
- `error`: Import stage failed and records `last_error`.
- `completed`: Import stage completed successfully.
- `skipped`: Import stage was intentionally not run because a required provider source for that stage is unavailable.

**Notes:**

- Default value is `scheduled`.

### NHL Game Source Status

**Name:** NHL game source status
**Storage location(s):** `nhl_game_source_statuses.status`
**Allowed values:**

- `available`
- `empty`
- `unavailable`

**Semantic meaning:**

- `available`: Required provider response exists and contains the minimum expected payload.
- `empty`: Provider response succeeded but lacks required records for that source.
- `unavailable`: Provider response failed or is unsupported for this import pipeline.

**Notes:**

- Source names are `pbp`, `boxscore`, and `shifts`.
- Empty or unavailable PBP or boxscore source statuses skip the core game import pipeline before PBP dispatch.
- Empty or unavailable shifts source statuses skip only shift-derived on-ice stages.

### NHL Game Import Run Action

**Name:** NHL game import run action
**Storage location(s):** `nhl_game_import_runs.action`
**Allowed values:**

- `discover`
- `process`
- `season-sync`

**Semantic meaning:**

- `discover`: Admin queued NHL game discovery for a date selection.
- `process`: Admin queued NHL game processing orchestrator jobs for a date selection.
- `season-sync`: Admin queued a season-level rollup from game summaries into season stats.

### NHL Game Import Run Mode

**Name:** NHL game import run mode
**Storage location(s):** `nhl_game_import_runs.mode`
**Allowed values:**

- `date`
- `season`
- `newdays`
- `range`
- `days`
- `default`

**Semantic meaning:**

- `date`: Single-date selection.
- `season`: Season-id date window.
- `newdays`: Window based on the oldest existing NHL import progress date.
- `range`: Explicit date range or single boundary date.
- `days`: Date window derived from a days count.
- `default`: Controller default selection, currently today for processing requests.

### NHL Game Import Run Status

**Name:** NHL game import run status
**Storage location(s):** `nhl_game_import_runs.status`, admin status payload
**Allowed values:**

- `queued`
- `running`
- `completed`
- `failed`

**Semantic meaning:**

- `queued`: Admin request has been accepted and jobs have been queued.
- `running`: Related NHL import progress rows are scheduled, running, or partially completed, or a season sync job is actively rolling up stats.
- `completed`: Related NHL import progress rows are all completed, or a season sync job completed successfully.
- `failed`: At least one related NHL import progress row is in error, or a season sync job failed.

---

### NHL Game Validation Type

**Name:** NHL game validation type
**Storage location(s):** `nhl_game_validations.validation_type`
**Allowed values:**

- `summary_boxscore`

**Semantic meaning:**

- `summary_boxscore`: Computed `nhl_game_summaries` rows compared against official `nhl_boxscores` rows.

**Notes:**

- Validation type values identify the artifact and source being compared.

### NHL Game Validation Status

**Name:** NHL game validation status
**Storage location(s):** `nhl_game_validations.status` (enum column)
**Allowed values:**

- `approved`
- `failed`
- `accepted_exception`
- `incomplete`
- `invalidated`

**Semantic meaning:**

- `approved`: Exact comparable totals matched and the validation can be trusted by downstream work.
- `failed`: One or more durable validation deltas exist.
- `accepted_exception`: An admin reviewed and accepted the failed validation as a known exception.
- `incomplete`: Comparable core totals passed, but at least one source-dependent field group could not be validated.
- `invalidated`: Preseason or exhibition validation produced deltas; deltas remain auditable, but the game may continue through import flow.

**Notes:**

- `failed` validation status blocks downstream import stages until rerun approval or accepted exception.
- `incomplete` does not mean parser failure; it indicates provider source coverage is incomplete.
- `invalidated` is allowed only for NHL game type `1`; game types `2` and `3` must continue to hard fail on validation deltas.

### NHL Game Validation Delta Severity

**Name:** NHL game validation delta severity
**Storage location(s):** `nhl_game_validation_deltas.severity` (enum column)
**Allowed values:**

- `error`
- `warning`

**Semantic meaning:**

- `error`: Delta blocks automatic validation approval.
- `warning`: Delta is informational and does not block automatic validation approval.

**Notes:**

- Current summary-boxscore validation persists blocking deltas as `error`.

---

### Admin Import Run Status

**Name:** Admin import run status
**Storage location(s):** `import_runs.status` (string column)
**Allowed values:**

- `working`
- `completed`
- `failed`

**Semantic meaning:**

- `working`: Admin-triggered import has started and has not reached a terminal state.
- `completed`: Admin-triggered import work finished successfully.
- `failed`: Admin-triggered import work failed and records `error_message`.

**Notes:**

- Existing import run rows default to `completed` when the lifecycle migration is applied.

### Admin Import Source

**Name:** Admin import source
**Storage location(s):** `import_runs.source` (string column)
**Allowed values currently used by the admin import registry:**

- `nhl`
- `nhl-resolve-players`
- `fantrax`
- `fantrax-category-definitions`
- `fantrax-league-category-backfill`
- `nhl-empty-games`
- `yahoo`
- `contracts`

**Semantic meaning:**

- `nhl`: NHL player discovery import.
- `nhl-resolve-players`: NHL identity reconciliation for existing canonical players with no NHL id.
- `fantrax`: Fantrax player import.
- `fantrax-category-definitions`: Fantrax scoring category definition and DynastyIQ stat alignment dictionary import.
- `fantrax-league-category-backfill`: Fantrax league scoring category row backfill from legacy league scoring JSON.
- `nhl-empty-games`: NHL game-derived import reset queued from the admin game import UI.
- `yahoo`: Yahoo fantasy hockey player import.
- `contracts`: CapWages contract import.

**Notes:**

- The column is not database constrained.
- Add admin import registry keys here before using new `import_runs.source` values.

---

### Fantasy Scoring Category Alignment Status

**Name:** Fantasy scoring category alignment status
**Storage location(s):** `fantasy_scoring_category_mappings.alignment_status`
**Allowed values:**

- `direct`
- `formula`
- `planned_derivation`
- `unsupported`
- `ignored_deprecated`

**Semantic meaning:**

- `direct`: Provider category maps to one stored DynastyIQ stat key.
- `formula`: Provider category can be computed from stored DynastyIQ stat keys.
- `planned_derivation`: Provider category has source data available, but a durable derived stat or alias is not implemented yet.
- `unsupported`: Provider category cannot currently be matched or computed.
- `ignored_deprecated`: Provider category is intentionally ignored because it is obsolete or not useful for supported workflows.

**Notes:**

- Unsupported and planned-derivation categories should be visible when a connected league uses them.

---

### Fantasy Scoring Category Mapping Source

**Name:** Fantasy scoring category mapping source
**Storage location(s):** `platform_league_scoring_categories.mapping_source`, legacy fallback `platform_leagues.scoring_settings.categories[].mapping_source`
**Allowed values:**

- `auto`
- `dictionary`
- `manual`

**Semantic meaning:**

- `auto`: The league category was mapped by a small provider-specific fallback matcher.
- `dictionary`: The league category was mapped from `fantasy_scoring_category_mappings`.
- `manual`: A user/admin manual league mapping overrides automatic mapping.

**Notes:**

- Manual mappings override dictionary and auto mappings, but dictionary support metadata may remain on the category row for warnings.

---

### Fantasy Scoring Manual Mapping Key

**Name:** Fantasy scoring manual mapping key
**Storage location(s):** `platform_league_scoring_categories.manual_mapping_key`, legacy fallback `platform_leagues.scoring_settings.manual_mappings`
**Allowed value prefixes:**

- `stat:`
- `dictionary:`
- `custom:`

**Semantic meaning:**

- `stat:`: Maps a provider category to a native DynastyIQ stat field, such as `stat:g`.
- `dictionary:`: Maps a provider category to an imported platform category formula, such as `dictionary:fantrax:Big Points 3`.
- `custom:`: Reserved for future custom user-defined formula mappings.

**Notes:**

- Older raw stat-key values are normalized to `stat:<key>` when scoring alignment is saved.

---

### Fantrax Scoring System Type

**Name:** Fantrax scoring system type
**Storage location(s):** `platform_leagues.scoring_settings.type`, fallback `platform_leagues.scoring_settings.raw_payload.scoringSystem.type`
**Allowed values currently emitted:**

- `points`
- `rotisserie`

**Semantic meaning:**

- `points`: Category weights are arithmetic rules that can produce read-time computed `Fantasy Pts` and `Fantasy Pts/G` when every active category has a supported DynastyIQ stat or formula mapping.
- `rotisserie`: Category values describe category participation/weighting and must not be summed into one player fantasy-point total.

**Notes:**

- The value is provider-sourced and string-backed. Add newly observed Fantrax scoring system types here before using them in branching logic.

### Fantrax Duplicate Player Type

**Name:** Fantrax duplicate player type
**Storage location(s):** `platform_leagues.settings.league_shape.duplicate_player_type`, raw provider fallback `getLeagueInfo.poolSettings.duplicatePlayerType`
**Allowed values currently observed:**

- `NONE`
- `ACROSS_DIVISIONS`

**Semantic meaning:**

- `NONE`: Fantrax players are unique across the whole league player universe.
- `ACROSS_DIVISIONS`: Fantrax players may be duplicated across division-scoped player pools.

**Notes:**

- The value is provider-sourced and string-backed. Unknown values must be retained for diagnostics but must not silently enable division-scoped availability.

### Fantrax Player Pool Scope

**Name:** Fantrax player pool scope
**Storage location(s):** `platform_leagues.settings.league_shape.player_pool_scope`
**Allowed values currently emitted:**

- `league`
- `division`
- `unknown`

**Semantic meaning:**

- `league`: Player availability is league-wide.
- `division`: Player availability is scoped by Fantrax division/pool labels.
- `unknown`: Fantrax did not expose enough setup data to derive player-pool scope safely.

### Fantrax Draft Shape

**Name:** Fantrax draft shape
**Storage location(s):** `platform_leagues.settings.league_shape.draft_shape`, `drafts.settings.draft_shape`
**Allowed values currently emitted:**

- `flat`
- `division_scoped`
- `unknown`

**Semantic meaning:**

- `flat`: Draft pick rows are league-wide and provider pick numbers may be treated as global within the draft.
- `division_scoped`: Draft rows include division/pool context and provider pick numbers may repeat across divisions.
- `unknown`: Draft shape could not be derived from the observed provider payload.

---

### Platform League Player Stat Scope

**Name:** Platform league player stat scope
**Storage location(s):** `platform_league_player_stats.scope`
**Allowed values:**

- `season`
- `period`
- `active_lineup`

**Semantic meaning:**

- `season`: Provider-earned player totals for a fantasy season.
- `period`: Provider-earned player totals for one provider scoring period.
- `active_lineup`: Provider-earned totals that count only active lineup usage when the provider exposes that scope separately.

**Notes:**

- Provider fantasy stat scopes represent what the fantasy platform counted, not raw NHL source-of-truth stats.

---

### NHL Player Transaction Source

**Name:** NHL player transaction source
**Storage location(s):** `nhl_player_transactions.source` (string column)
**Allowed values:**

- `capwages`

**Semantic meaning:**

- `capwages`: Real hockey player acquisition and contract-signing history sourced from CapWages player detail payloads.

**Notes:**

- Fantasy roster transaction sources do not belong in this enum.

---

## Fantasy Platforms

### Platform

**Name:** Fantasy platform
**Storage location(s):** `platform_leagues.platform`, `platform_player_ids.platform`, `platform_roster_memberships.platform`, `integration_secrets.provider`, league creation validation
**Allowed values:**

- `fantrax`
- `yahoo`
- `espn`

**Semantic meaning:**

- `fantrax`: Fantrax fantasy platform.
- `yahoo`: Yahoo fantasy platform.
- `espn`: ESPN fantasy platform.

**Notes:**

- `integration_secrets.provider` allows additional non-league providers; see Integration Secret Provider.

### Integration Secret Provider

**Name:** Integration secret provider
**Storage location(s):** `integration_secrets.provider` (enum column)
**Allowed values:**

- `fantrax`
- `yahoo`
- `espn`
- `rotowire`
- `discord`

**Semantic meaning:**

- `fantrax`: Fantrax user secret.
- `yahoo`: Yahoo user integration secret.
- `espn`: ESPN user integration secret.
- `rotowire`: Rotowire user integration secret.
- `discord`: Discord user integration secret.

### Integration Secret Status

**Name:** Integration secret status
**Storage location(s):** `integration_secrets.status` (enum column)
**Allowed values:**

- `connected`
- `needs_setup`
- `error`

**Semantic meaning:**

- `connected`: Integration is configured and usable.
- `needs_setup`: Integration exists but is not fully configured.
- `error`: Integration is configured but currently failing.

**Notes:**

- Default value is `needs_setup`.
- Fantrax connect flow writes `connected` after validating a secret and importing leagues.

### Fantasy Integration Readiness Status

**Name:** Fantasy integration readiness status
**Storage location(s):** `FantasyIntegrationState` response payload (derived, not stored)
**Allowed values:**

- `disconnected`
- `connected`
- `ready`

**Semantic meaning:**

- `disconnected`: No active provider credential or grant exists for the user.
- `connected`: A valid provider credential or grant exists, but active league ownership has not been materialized.
- `ready`: A valid provider credential or grant exists and the user has at least one active league assignment for that provider.

**Notes:**

- Leagues navigation visibility is driven by the derived `show_leagues` flag, not by provider credential storage alone.
- Fantrax and Yahoo expose this common state shape even though Fantrax uses `integration_secrets` and Yahoo uses `yahoo_fantasy_connections`.

### Platform Roster Membership Status

**Name:** Platform roster membership status
**Storage location(s):** `platform_roster_memberships.status` (nullable enum column)
**Allowed values:**

- `active`
- `bench`
- `ir`
- `na`
- `taxi`

**Semantic meaning:**

- `active`: Player is active on a roster.
- `bench`: Player is on bench.
- `ir`: Player is in an injured-reserve slot.
- `na`: Player is in a not-active/not-available slot.
- `taxi`: Player is on taxi squad.

### League Sync Broadcast Status

**Name:** League sync broadcast status
**Storage location(s):** `LeagueSyncStatusUpdated.status` broadcast payload
**Allowed values:**

- `processing`
- `completed`
- `failed`

**Semantic meaning:**

- `processing`: A user-visible league sync job has started processing.
- `completed`: A user-visible league sync job completed successfully.
- `failed`: A user-visible league sync job failed.

**Notes:**

- These values are not persisted; they are broadcast to the private user channel for Leagues UI feedback.

### Platform League Roster Slot Type

**Name:** Platform league roster slot type
**Storage location(s):** `platform_league_roster_slots.slot_type` (nullable string column)
**Allowed values:**

- `starter`
- `bench`
- `injured`
- `minor`
- `utility`

**Semantic meaning:**

- `starter`: Normal active lineup slot.
- `bench`: Bench slot.
- `injured`: Injured-reserve slot.
- `minor`: Minor/not-active style slot.
- `utility`: Flexible lineup slot.

**Notes:**

- The column is not database constrained.
- Provider-specific roster position labels are stored in `platform_league_roster_slots.slot`.

### League Platform Link Status

**Name:** League platform link status
**Storage location(s):** `league_platform_league.status` (nullable string column)
**Allowed values currently documented by migration comments/code:**

- `active`
- `pending`
- `unlinked`

**Semantic meaning:**

- `active`: Platform league link is the active link for the internal league.
- `pending`: Link is staged or awaiting confirmation.
- `unlinked`: Link has been detached/inactivated.

**Notes:**

- The column is not database constrained.
- `League::primaryPlatformLeague()` filters for pivot status `active`.

---

## Player Identity Resolution

### Player External Identity Provider

**Name:** External player identity provider
**Storage location(s):** `player_external_identities.provider` (string column)
**Allowed values:**

- `nhl`
- `nhl_draft`
- `fantrax`
- `yahoo`
- `capwages`
- `eliteprospects`

**Semantic meaning:**

- `nhl`: NHL API player identity.
- `nhl_draft`: NHL draft-pick identity for drafted players that do not yet have an NHL player id.
- `fantrax`: Fantrax API player identity.
- `yahoo`: Yahoo Fantasy Sports API player identity.
- `capwages`: CapWages API player identity.
- `eliteprospects`: EliteProspects API player identity.

**Notes:**

- The column is not database constrained.
- NHL is the initial authority provider allowed to create canonical `players` rows during import.
- NHL draft identities may create minimal canonical prospect `players` rows with `nhl_id = NULL` only after checking existing canonical players by normalized name and compatible position type.

### Player External Identity Match Status

**Name:** External player identity match status
**Storage location(s):** `player_external_identities.match_status` (string column)
**Allowed values:**

- `matched`
- `candidate`
- `unmatched`
- `ignored`
- `conflict`

**Semantic meaning:**

- `matched`: Identity is linked to one canonical player.
- `candidate`: Identity has one or more possible canonical player matches that require review or a later resolver pass.
- `unmatched`: Identity has no canonical player match yet.
- `ignored`: Identity should not participate in matching.
- `conflict`: Identity matched ambiguously or disagrees with an existing link.

**Notes:**

- The column is not database constrained.
- Default value is `unmatched`.
- `matched` identities must have a `player_id`.

### Player External Identity Unmatched Reason

**Name:** External player identity unmatched reason
**Storage location(s):** `player_external_identities.unmatched_reason` (nullable string column)
**Allowed values currently used by code:**

- `no_canonical_player`
- `missing_provider_player_id`
- `insufficient_identity_data`
- `multiple_candidates`
- `provider_payload_missing_name`

**Semantic meaning:**

- `no_canonical_player`: No canonical player exists for a non-authority provider identity.
- `missing_provider_player_id`: Provider payload did not include a durable player ID.
- `insufficient_identity_data`: Provider payload lacks enough matching fields.
- `multiple_candidates`: More than one plausible canonical player exists.
- `provider_payload_missing_name`: Provider payload lacks usable name fields.

**Notes:**

- The column is not database constrained.
- The value is nullable for matched and ignored identities.

---

## Stats & Rankings

### Visibility

**Name:** Content visibility
**Storage location(s):** `perspectives.visibility`, `ranking_profiles.visibility`, `player_rankings.visibility` (enum columns)
**Allowed values:**

- `private`
- `public_authenticated`
- `public_guest`

**Semantic meaning:**

- `private`: Visible only to the owner/scope allowed by the app.
- `public_authenticated`: Visible to authenticated users.
- `public_guest`: Publicly visible, including guests.

**Notes:**

- Default value is `private`.
- Seeded perspectives use `public_guest`.

### Sport

**Name:** Sport
**Storage location(s):** `perspectives.sport`, `ranking_profiles.sport`, `player_rankings.sport` (enum columns), `leagues.sport` and `platform_leagues.sport` (string columns)
**Allowed values in enum-backed ranking/perspective tables:**

- `hockey`
- `football`
- `basketball`

**Semantic meaning:**

- `hockey`: Hockey/NHL domain.
- `football`: Football domain.
- `basketball`: Basketball domain.

**Notes:**

- Default value is `hockey`.
- League sport columns are strings, but current code defaults new internal/platform leagues to `hockey`.

### Stats Slice

**Name:** Stats slice
**Storage location(s):** Stats controller request validation; Discord command `enum_options.slice`
**Allowed values:**

- `total`
- `pgp`
- `p60`

**Semantic meaning:**

- `total`: Raw total stats.
- `pgp`: Per-game-played rate.
- `p60`: Per-60-minute rate.

### Stats Resource

**Name:** Stats resource
**Storage location(s):** Stats controller request validation; Discord command `enum_options.resource`
**Allowed values in StatsController:**

- `players`
- `units`

**Allowed values in seeded Discord command options:**

- `player`
- `unit`
- `team`

**Semantic meaning:**

- `players` / `player`: Player stats resource.
- `units` / `unit`: Unit stats resource.
- `team`: Team stats resource exposed in Discord command metadata.

**Notes:**

- There is a naming mismatch between HTTP stats validation (`players`, `units`) and seeded Discord command options (`player`, `unit`, `team`).
- Treat this as an integration boundary difference until unified by a code change and this document update.

### Stats Period

**Name:** Stats period
**Storage location(s):** Stats controller request validation; Discord command `enum_options.period`
**Allowed values in current `StatsController`:**

- `season`
- `range`
- `lastWeek`
- `thisWeek`
- `past30days`

**Allowed values in legacy/alternate `StatsController_org` and Discord command options:**

- `season`
- `range`
- `lastweek`
- `thisweek`
- `last30`

**Semantic meaning:**

- `season`: Full selected season.
- `range`: Explicit date range.
- `lastWeek` / `lastweek`: Previous week window.
- `thisWeek` / `thisweek`: Current week window.
- `past30days` / `last30`: Recent 30-day window.

**Notes:**

- Casing and naming differ between current controller validation and Discord/legacy metadata.
- New code should prefer the current `StatsController` values unless explicitly working on Discord command parsing.

---

## Discord

### Discord Command Handler Kind

**Name:** Discord command handler kind
**Storage location(s):** `discord_commands.handler_kind` (enum column)
**Allowed values:**

- `route`
- `service`
- `job`

**Semantic meaning:**

- `route`: Command dispatches to an application route.
- `service`: Command dispatches to a service handler.
- `job`: Command dispatches a queued job.

### Discord Command HTTP Method

**Name:** Discord command HTTP method
**Storage location(s):** `discord_commands.http_method` (nullable enum column)
**Allowed values:**

- `GET`
- `POST`

**Semantic meaning:**

- `GET`: Command route uses GET.
- `POST`: Command route uses POST.

**Notes:**

- May be `NULL` for service/job handlers.

### Discord Command Auth Scope

**Name:** Discord command auth scope
**Storage location(s):** `discord_commands.auth_scope` (string column)
**Allowed values currently seeded:**

- `user`

**Semantic meaning:**

- `user`: Command runs in the context of an authenticated/linked user.

**Notes:**

- The column is not database constrained.
- Add additional auth scopes here before seeding or using them.

---

## Provider Accounts & Memberships

### Provider Account Provider

**Name:** Provider account provider
**Storage location(s):** `provider_accounts.provider`, `membership_tiers.provider`, `memberships.provider` (string columns)
**Allowed values currently used:**

- `patreon`

**Semantic meaning:**

- `patreon`: Patreon creator/campaign provider.

**Notes:**

- Provider account columns are string-backed, not database enums.
- Patreon OAuth, nightly sync, webhook handling, tier sync, and member sync all use `patreon`.

### Yahoo Fantasy Connection Status

**Name:** Yahoo Fantasy connection status
**Storage location(s):** `yahoo_fantasy_connections.status` (string column)
**Allowed values currently used:**

- `connected`
- `offline`

**Semantic meaning:**

- `connected`: Yahoo OAuth grant exists and token/API handling is expected to work.
- `offline`: Yahoo OAuth grant exists but token/API handling failed.

**Notes:**

- The column is not database constrained.
- `connected` is the database default for new Yahoo OAuth callbacks.

### Provider Account Status

**Name:** Provider account status
**Storage location(s):** `provider_accounts.status` (string column)
**Allowed values:**

- `disconnected`
- `connected`
- `offline`

**Semantic meaning:**

- `disconnected`: No active provider connection exists. This is the database default.
- `connected`: Provider account is connected and last sync/token flow succeeded.
- `offline`: Provider account exists but sync/token handling failed.

**Notes:**

- The column is not database constrained.
- Patreon connection writes `connected`; failed Patreon sync writes `offline`.

### Membership Status

**Name:** Membership status
**Storage location(s):** `memberships.status` (string column), `CommunityMemberRequest`, Patreon member status mapper
**Allowed values:**

- `active`
- `declined`
- `former_member`
- `deleted`

**Semantic meaning:**

- `active`: Member is currently active.
- `declined`: Provider reports a declined patron/member.
- `former_member`: Provider reports a former patron/member.
- `deleted`: Provider reports the member was deleted.

**Notes:**

- Database default is `active`.
- Manual community-member validation allows these four values.
- Patreon maps `declined_patron` -> `declined`, `former_patron` -> `former_member`, `deleted` -> `deleted`, and all other provider statuses -> `active`.

### Patreon Patron Status (External)

**Name:** Patreon patron status
**Storage location(s):** Incoming Patreon payload `attributes.patron_status`; not persisted directly
**Mapped external values:**

- `declined_patron`
- `former_patron`
- `deleted`

**Semantic meaning:**

- `declined_patron`: Maps to local membership status `declined`.
- `former_patron`: Maps to local membership status `former_member`.
- `deleted`: Maps to local membership status `deleted`.

**Notes:**

- Unknown or empty external status maps to local `active`.

### Membership Event Type

**Name:** Membership event type
**Storage location(s):** `membership_events.event_type` (string column)
**Allowed values currently emitted:**

- `tier.changed`
- `pledge.changed`
- `status.changed`
- `patreon.webhook`

**Semantic meaning:**

- `tier.changed`: Membership tier changed during sync.
- `pledge.changed`: Membership pledge amount changed during sync.
- `status.changed`: Membership status changed during sync.
- `patreon.webhook`: Patreon webhook was processed.

**Notes:**

- The column is not database constrained.

---

## User Preferences

### User Preference Key

**Name:** User preference key
**Storage location(s):** `user_preferences.key` (string column), `UserPreferencesController` validation
**Allowed values:**

- `notifications.discord.dm`
- `notifications.discord.channel`
- `notifications.discord.channel-name`

**Semantic meaning:**

- `notifications.discord.dm`: User preference for Discord direct-message notifications.
- `notifications.discord.channel`: User preference for Discord channel notifications.
- `notifications.discord.channel-name`: User preference for Discord channel name.

**Notes:**

- The column is string-backed but controller validation restricts writes to these keys.

---

## Fantrax Drafts

### Draft Source Type

**Name:** Draft source type
**Storage location(s):** `drafts.source_type` (string column)
**Allowed values currently emitted:**

- `platform_mirror`
- `dynastyiq_managed`
- `hybrid`

**Semantic meaning:**

- `platform_mirror`: Draft state is mirrored from an external platform draft.
- `dynastyiq_managed`: Draft picks are managed directly inside DynastyIQ.
- `hybrid`: Draft is managed in DynastyIQ for a connected platform league and may be exported or reconciled later.

**Notes:**

- The column is string-backed and not database constrained.

---

### Draft Status

**Name:** Draft status
**Storage location(s):** `drafts.status` (string column)
**Allowed values currently emitted:**

- `unknown`
- `scheduled`
- `live`
- `paused`
- `complete`

**Semantic meaning:**

- `unknown`: Draft status could not be determined.
- `scheduled`: Draft has not started.
- `live`: Draft is actively running.
- `paused`: Draft is temporarily stopped by a commissioner or system rule.
- `complete`: Draft has ended.

**Notes:**

- The column is string-backed and not database constrained.

---

### Draft Pick Status

**Name:** Draft pick status
**Storage location(s):** `draft_picks.status` (string column)
**Allowed values currently emitted:**

- `pending`
- `on_clock`
- `picked`
- `skipped`
- `expired`

**Semantic meaning:**

- `pending`: Pick is not yet active and no player has been selected.
- `on_clock`: Pick is the current active pick.
- `picked`: Pick has a selected player.
- `skipped`: Pick was intentionally skipped.
- `expired`: Pick clock elapsed without a selection.

**Notes:**

- The column is string-backed and not database constrained.

---

### Fantrax Draft State Status

**Name:** Fantrax draft state status
**Storage location(s):** `fantrax_draft_states.status` (string column)
**Allowed values currently emitted:**

- `unknown`
- `scheduled`
- `live`
- `complete`

**Semantic meaning:**

- `unknown`: Draft date/status could not be determined from the provider payload.
- `scheduled`: Draft date is in the future.
- `live`: Draft date has passed and Fantrax reports current draft picks.
- `complete`: Draft date has passed and Fantrax reports no current draft picks.

**Notes:**

- The column is string-backed and not database constrained.

---

### Cap Contract Projection Source

**Name:** Cap contract projection source
**Storage location(s):** `cap_contract_projections.source` (string column)
**Allowed values currently emitted:**

- `system`
- `user`

**Semantic meaning:**

- `system`: Projection is based on an application-generated planning default.
- `user`: Projection was edited and persisted by the current user.

**Notes:**

- The column is string-backed and not database constrained.

---

### Cap Contract Projection Basis

**Name:** Cap contract projection basis
**Storage location(s):** `cap_contract_projections.basis` (string column)
**Allowed values currently emitted:**

- `last_cap_hit`
- `manual`

**Semantic meaning:**

- `last_cap_hit`: Projection default was derived from the player's last expired cap hit.
- `manual`: Projection value was directly entered by the user.

**Notes:**

- The column is string-backed and not database constrained.

---

## Conflicts / Ambiguities Report

The following enum-like areas are intentionally documented because they are not fully constrained in the database or are inconsistent across code paths:

- `players.status` is a string with default `active`; migration comments mention possible future values but no code-level validation currently canonicalizes them.
- `provider_accounts.status` is a string with default `disconnected`; code also writes `connected` and `offline`.
- `league_platform_league.status` is a string; migration comments mention `active`, `pending`, and `unlinked`, while code currently filters for `active`.
- Stats resource values differ between HTTP validation (`players`, `units`) and Discord command metadata (`player`, `unit`, `team`).
- Stats period values differ in casing/naming between current stats validation (`lastWeek`, `thisWeek`, `past30days`) and legacy/Discord metadata (`lastweek`, `thisweek`, `last30`).

**End of ENUMS**
