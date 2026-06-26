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

---

## NHL Import Pipeline

### NHL Import Type

**Name:** NHL import type  
**Storage location(s):** `nhl_import_progress.import_type` (enum column)  
**Allowed values:**

- `pbp`
- `summary`
- `shifts`
- `boxscore`
- `shift-units`
- `connect-events`
- `sum-game-units`

**Semantic meaning:**

- `pbp`: Import play-by-play events.
- `summary`: Summarize play-by-play into per-player game summaries.
- `shifts`: Import raw NHL shift data.
- `boxscore`: Import NHL boxscore data.
- `shift-units`: Build NHL unit shift windows.
- `connect-events`: Link play-by-play events to unit shifts.
- `sum-game-units`: Aggregate per-game unit summaries.

**Notes:**

- `NhlImportOrchestrator` advances these stages in the order listed above.

### NHL Import Status

**Name:** NHL import status  
**Storage location(s):** `nhl_import_progress.status` (enum column)  
**Allowed values:**

- `scheduled`
- `running`
- `error`
- `completed`

**Semantic meaning:**

- `scheduled`: Import stage is queued for future work.
- `running`: Import stage has been claimed by a worker.
- `error`: Import stage failed and records `last_error`.
- `completed`: Import stage completed successfully.

**Notes:**

- Default value is `scheduled`.

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
- `fantrax`
- `capwages`
- `eliteprospects`

**Semantic meaning:**

- `nhl`: NHL API player identity.
- `fantrax`: Fantrax API player identity.
- `capwages`: CapWages API player identity.
- `eliteprospects`: EliteProspects API player identity.

**Notes:**

- The column is not database constrained.
- NHL is the initial authority provider allowed to create canonical `players` rows during import.

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

## Conflicts / Ambiguities Report

The following enum-like areas are intentionally documented because they are not fully constrained in the database or are inconsistent across code paths:

- `players.status` is a string with default `active`; migration comments mention possible future values but no code-level validation currently canonicalizes them.
- `provider_accounts.status` is a string with default `disconnected`; code also writes `connected` and `offline`.
- `league_platform_league.status` is a string; migration comments mention `active`, `pending`, and `unlinked`, while code currently filters for `active`.
- Stats resource values differ between HTTP validation (`players`, `units`) and Discord command metadata (`player`, `unit`, `team`).
- Stats period values differ in casing/naming between current stats validation (`lastWeek`, `thisWeek`, `past30days`) and legacy/Discord metadata (`lastweek`, `thisweek`, `last30`).

**End of ENUMS**
