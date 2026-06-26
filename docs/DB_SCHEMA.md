# Database Schema Inventory (DB_SCHEMA)

This document inventories **all database tables and columns** as defined by migrations.
It exists to bootstrap **accurate, lossless context** for humans and AI.

This file is **descriptive only**.
Migrations remain the **sole source of truth**.

---

## Global Conventions

- **DDL Authority:** `database/migrations/`
- **Enum Values:** Defined inline in migrations; if `docs/ENUMS.md` is added later, enum documentation should move there.
- **Tenant Scoping:** This codebase is organization/user scoped, not tenant scoped. Tables with `organization_id` are community-owned; user-owned tables use `user_id`.
- **Indexes:**
  - _Explicit_ -> declared with `->index()` / `->unique()` / `->primary()`
  - _Implicit (FK index)_ -> created automatically with foreign keys
- **External IDs:** NHL, Fantrax, Discord, Patreon, and other platform identifiers are often stored as source-system IDs and are not always declared as foreign keys.

---

## Table Index

- cache
- cache_locks
- contract_seasons
- contracts
- discord_commands
- discord_organizations
- discord_servers
- event_unit_shifts
- failed_jobs
- fantrax_players
- import_runs
- integration_secrets
- job_batches
- jobs
- league_platform_league
- league_user_teams
- leagues
- member_profiles
- membership_events
- membership_tiers
- memberships
- nhl_boxscores
- nhl_game_summaries
- nhl_games
- nhl_import_progress
- nhl_season_stats
- nhl_shifts
- nhl_unit_game_summaries
- nhl_unit_players
- nhl_unit_shifts
- nhl_units
- organization_leagues
- organization_user
- organizations
- password_reset_tokens
- personal_access_tokens
- perspectives
- platform_leagues
- platform_player_ids
- platform_roster_memberships
- platform_teams
- player_imports
- player_rankings
- players
- play_by_plays
- provider_accounts
- ranking_profiles
- role_user
- roles
- season_stats
- sessions
- social_accounts
- stats
- user_preferences
- users

---

## cache

**Organization-owned:** No
**Purpose:** Laravel application cache store.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| key | string | No | Primary key |
| value | mediumText | No | Serialized cache value |
| expiration | integer | No | Expiration timestamp |

### Keys & Indexes

- PK: `key`

---

## cache_locks

**Organization-owned:** No
**Purpose:** Laravel distributed cache locks.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| key | string | No | Primary key |
| owner | string | No | Lock owner token |
| expiration | integer | No | Expiration timestamp |

### Keys & Indexes

- PK: `key`

---

## contract_seasons

**Organization-owned:** No
**Purpose:** Season-by-season salary and cap details for player contracts.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| contract_id | bigint | No | FK -> contracts.id (CASCADE) |
| season_key | unsignedInteger | No | Raw season code, e.g. `20252026` |
| label | string(7) | Yes | Human-readable label, e.g. `2025-26` |
| clause | string | Yes | Contract clause |
| cap_hit | unsignedBigInteger | Yes | Cap hit in source units |
| aav | unsignedBigInteger | Yes | Average annual value |
| performance_bonuses | unsignedBigInteger | Yes | Performance bonuses |
| signing_bonuses | unsignedBigInteger | Yes | Signing bonuses |
| base_salary | unsignedBigInteger | Yes | Base salary |
| total_salary | unsignedBigInteger | Yes | Total salary |
| minors_salary | unsignedBigInteger | Yes | Minors salary |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(contract_id, season_key)`
- Implicit (FK index): `contract_id`

---

## contracts

**Organization-owned:** No
**Purpose:** Top-level NHL contract records imported for players.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| contract_type | string | No | Source contract type |
| contract_length | string | Yes | Contract length |
| contract_value | unsignedBigInteger | Yes | Total contract value |
| expiry_status | string | Yes | Expiry status |
| signing_team | string | Yes | Signing team |
| signing_date | date | Yes | Signing date |
| signed_by | string | Yes | Signing authority/source |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Implicit (FK index): `player_id`

---

## discord_commands

**Organization-owned:** No
**Purpose:** Registry of Discord bot commands, handlers, defaults, and command metadata.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| command_slug | string(120) | No | Primary key |
| name | string(80) | No | Display/command name |
| parent_slug | string(120) | Yes | FK -> discord_commands.command_slug (SET NULL) |
| description | text | Yes | Command description |
| handler_kind | enum | No | `route`, `service`, `job` |
| handler_ref | string | No | Handler reference |
| http_method | enum | Yes | `GET`, `POST` |
| usage | text | Yes | Usage text |
| link_path | string(255) | Yes | Related app path |
| brand_hint | text | Yes | Display hint |
| param_keys | json | Yes | Parameter keys |
| enum_options | json | Yes | Option metadata |
| has_defaults | boolean | No | Defaults to `false` |
| defaults | json | Yes | Default payload |
| allowed_overrides | json | Yes | Override metadata |
| max_sorts | unsignedSmallInteger | No | Defaults to `1` |
| auth_scope | string(32) | No | Defaults to `user` |
| enabled | boolean | No | Defaults to `true` |
| version | unsignedSmallInteger | No | Defaults to `1` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `command_slug`
- Index: `(parent_slug, name)`
- Implicit (FK index): `parent_slug`

---

## discord_organizations

**Organization-owned:** Yes
**Purpose:** Legacy/auxiliary mapping between organizations and connected Discord servers.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| discord_server_id | bigint | No | FK -> discord_servers.id (CASCADE) |
| linked_at | timestamp | Yes | Link timestamp |
| meta | json | Yes | Provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `discord_server_id`
- Index: `(organization_id, discord_server_id)`
- Implicit (FK index): `organization_id`
- Implicit (FK index): `discord_server_id`

---

## discord_servers

**Organization-owned:** Yes
**Purpose:** Discord guilds attached to organizations for community/bot workflows.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| discord_guild_id | string(32) | No | Discord guild ID |
| discord_guild_name | string | Yes | Discord guild name |
| installed_by_discord_user_id | string(32) | Yes | Discord installer user ID |
| access_token | text | Yes | OAuth token |
| refresh_token | text | Yes | OAuth refresh token |
| token_expires_at | timestamp | Yes | Token expiry |
| granted_permissions | string | Yes | Discord permission grant |
| meta | json | Yes | Provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `discord_guild_id`
- Unique: `(organization_id, discord_guild_id)`
- Index: `installed_by_discord_user_id`
- Implicit (FK index): `organization_id`

---

## event_unit_shifts

**Organization-owned:** No
**Purpose:** Join table connecting play-by-play events to NHL unit shifts.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| event_id | bigint | No | FK -> play_by_plays.id (CASCADE) |
| unit_shift_id | bigint | No | FK -> nhl_unit_shifts.id (CASCADE) |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(event_id, unit_shift_id)`
- Implicit (FK index): `event_id`
- Implicit (FK index): `unit_shift_id`

---

## failed_jobs

**Organization-owned:** No
**Purpose:** Laravel failed queue job records.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| uuid | string | No | Unique job UUID |
| connection | text | No | Queue connection |
| queue | text | No | Queue name |
| payload | longText | No | Serialized payload |
| exception | longText | No | Exception text |
| failed_at | timestamp | No | Defaults to current timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `uuid`

---

## fantrax_players

**Organization-owned:** No
**Purpose:** Fantrax player identity records linked to canonical DynastyIQ players.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | Yes | FK -> players.id (SET NULL), unique |
| fantrax_id | string | No | Unique Fantrax player ID |
| statsinc_id | unsignedInteger | Yes | Stats Inc ID |
| rotowire_id | unsignedInteger | Yes | Rotowire ID |
| sport_radar_id | string(255) | Yes | Sportradar ID |
| team | string | Yes | Team from Fantrax payload |
| name | string | Yes | Player name from Fantrax payload |
| position | string | Yes | Position from Fantrax payload |
| raw_meta | json | Yes | Raw source metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `player_id`
- Unique: `fantrax_id`
- Implicit (FK index): `player_id`

---

## import_runs

**Organization-owned:** No
**Purpose:** Lightweight tracking for completed import runs by source.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| source | string | No | Import source key |
| ran_at | timestamp | No | Run timestamp |
| batch_id | string | Yes | Optional batch identifier |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `source`

---

## integration_secrets

**Organization-owned:** No; user-owned
**Purpose:** Per-user integration secret storage, currently used for Fantrax connection state.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| provider | enum | No | `fantrax`, `yahoo`, `espn`, `rotowire`, `discord` |
| secret | text | Yes | Stored integration secret |
| status | enum | No | `connected`, `needs_setup`, `error`; defaults to `needs_setup` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(user_id, provider)`
- Implicit (FK index): `user_id`

---

## job_batches

**Organization-owned:** No
**Purpose:** Laravel queue batch tracking.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | string | No | Primary key |
| name | string | No | Batch name |
| total_jobs | integer | No | Total job count |
| pending_jobs | integer | No | Pending job count |
| failed_jobs | integer | No | Failed job count |
| failed_job_ids | longText | No | Failed job IDs |
| options | mediumText | Yes | Serialized batch options |
| cancelled_at | integer | Yes | Cancellation timestamp |
| created_at | integer | No | Creation timestamp |
| finished_at | integer | Yes | Finish timestamp |

### Keys & Indexes

- PK: `id`

---

## jobs

**Organization-owned:** No
**Purpose:** Laravel queue jobs.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| queue | string | No | Queue name |
| payload | longText | No | Serialized payload |
| attempts | unsignedTinyInteger | No | Attempt count |
| reserved_at | unsignedInteger | Yes | Reservation timestamp |
| available_at | unsignedInteger | No | Availability timestamp |
| created_at | unsignedInteger | No | Creation timestamp |

### Keys & Indexes

- PK: `id`
- Index: `queue`

---

## league_platform_league

**Organization-owned:** Indirectly via `leagues`
**Purpose:** Links internal community leagues to external platform leagues.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| league_id | bigint | No | FK -> leagues.id (CASCADE) |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| linked_at | timestamp | Yes | Link timestamp |
| status | string | Yes | Link status, e.g. `active`, `pending`, `unlinked` |
| meta | json | Yes | Link metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(league_id, platform_league_id)` (`uq_league_platform_link`)
- Unique: `platform_league_id` (`uq_external_single_internal`)
- Index: `(league_id, status, linked_at)` (`ix_league_status_linked`)
- Index: `(platform_league_id, linked_at)` (`ix_pl_linked`)
- Implicit (FK index): `league_id`
- Implicit (FK index): `platform_league_id`

### Notes

- The migration includes commented examples for enforcing one active platform link per league; those commented statements are not active DDL.

---

## league_user_teams

**Organization-owned:** No; user/platform-league owned
**Purpose:** Maps users to their active teams in external platform leagues.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| team_id | bigint | No | FK -> platform_teams.id (CASCADE) |
| is_active | boolean | No | Defaults to `true` |
| extras | json | Yes | Platform metadata |
| synced_at | timestamp | Yes | Last sync timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(user_id, platform_league_id)` (`uq_user_league`)
- Unique: `(user_id, team_id)` (`uq_user_team`)
- Index: `(platform_league_id, team_id)` (`idx_league_team_lookup`)
- Implicit (FK index): `user_id`
- Implicit (FK index): `platform_league_id`
- Implicit (FK index): `team_id`

---

## leagues

**Organization-owned:** Indirectly through `organization_leagues`
**Purpose:** Internal DynastyIQ league records that may link to one or more external platform leagues.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| name | string | No | League name; not globally unique |
| sport | string | Yes | Sport key |
| synced_at | timestamp | Yes | Last sync timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `name`

---

## member_profiles

**Organization-owned:** Yes
**Purpose:** Canonical member identities within an organization, populated from provider data such as Patreon.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| email | string | Yes | Member email |
| display_name | string | Yes | Member display name |
| avatar_url | string | Yes | Avatar URL |
| external_ids | json | Yes | Provider IDs |
| metadata | json | Yes | Provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(organization_id, email)`
- Implicit (FK index): `organization_id`

---

## membership_events

**Organization-owned:** Indirectly through provider account or membership
**Purpose:** Audit/event log for membership changes and Patreon webhook processing.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| membership_id | bigint | Yes | FK -> memberships.id (SET NULL) |
| provider_account_id | bigint | Yes | FK -> provider_accounts.id (SET NULL) |
| event_type | string | No | Event type |
| payload | json | Yes | Event payload |
| occurred_at | timestamp | Yes | Event occurrence timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Implicit (FK index): `membership_id`
- Implicit (FK index): `provider_account_id`

---

## membership_tiers

**Organization-owned:** Yes
**Purpose:** Membership tier records synced from provider accounts such as Patreon.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| provider_account_id | bigint | Yes | FK -> provider_accounts.id (CASCADE) |
| provider | string | Yes | Provider key |
| external_id | string | Yes | Provider tier ID |
| name | string | No | Tier name |
| amount_cents | unsignedBigInteger | Yes | Pledge amount in cents |
| currency | string(3) | Yes | Currency code |
| description | text | Yes | Tier description |
| is_active | boolean | No | Defaults to `true` |
| synced_at | timestamp | Yes | Last sync timestamp |
| metadata | json | Yes | Provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(provider_account_id, external_id)`
- Implicit (FK index): `organization_id`
- Implicit (FK index): `provider_account_id`

---

## memberships

**Organization-owned:** Yes
**Purpose:** Provider-backed memberships for organization/community members.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| provider_account_id | bigint | Yes | FK -> provider_accounts.id (CASCADE) |
| member_profile_id | bigint | No | FK -> member_profiles.id (CASCADE) |
| membership_tier_id | bigint | Yes | FK -> membership_tiers.id (SET NULL) |
| provider | string | Yes | Provider key |
| provider_member_id | string | Yes | Provider member ID |
| status | string | No | Defaults to `active` |
| pledge_amount_cents | unsignedBigInteger | Yes | Pledge amount in cents |
| currency | string(3) | Yes | Currency code |
| started_at | timestamp | Yes | Membership start |
| ended_at | timestamp | Yes | Membership end |
| synced_at | timestamp | Yes | Last sync timestamp |
| metadata | json | Yes | Provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(provider_account_id, provider_member_id)`
- Implicit (FK index): `organization_id`
- Implicit (FK index): `provider_account_id`
- Implicit (FK index): `member_profile_id`
- Implicit (FK index): `membership_tier_id`

---

## nhl_boxscores

**Organization-owned:** No
**Purpose:** Per-game NHL boxscore stat lines imported from NHL data.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | unsignedBigInteger | No | NHL game ID; indexed |
| nhl_player_id | unsignedBigInteger | Yes | NHL player ID; indexed |
| nhl_team_id | unsignedBigInteger | No | NHL team ID; indexed |
| sweater_number | integer | No | Defaults to `0` |
| goals | integer | No | Defaults to `0` |
| assists | integer | No | Defaults to `0` |
| points | integer | No | Defaults to `0` |
| plus_minus | integer | No | Defaults to `0` |
| penalty_minutes | integer | No | Defaults to `0` |
| toi | string | Yes | Raw time on ice |
| toi_seconds | integer | Yes | Time on ice in seconds |
| sog | integer | No | Shots on goal; defaults to `0` |
| hits | integer | No | Defaults to `0` |
| blocks | integer | No | Defaults to `0` |
| faceoffs_won | integer | No | Defaults to `0` |
| faceoffs_lost | integer | No | Defaults to `0` |
| faceoff_win_percentage | float | No | Defaults to `0` |
| power_play_goals | integer | No | Defaults to `0` |
| power_play_assists | integer | No | Defaults to `0` |
| short_handed_goals | integer | No | Defaults to `0` |
| short_handed_assists | integer | No | Defaults to `0` |
| giveaways | integer | No | Defaults to `0` |
| takeaways | integer | No | Defaults to `0` |
| goals_against | integer | No | Defaults to `0` |
| saves | integer | No | Defaults to `0` |
| shots_against | integer | No | Defaults to `0` |
| ev_saves | integer | No | Defaults to `0` |
| ev_shots_against | integer | No | Defaults to `0` |
| pp_saves | integer | No | Defaults to `0` |
| pp_shots_against | integer | No | Defaults to `0` |
| pk_saves | integer | No | Defaults to `0` |
| pk_shots_against | integer | No | Defaults to `0` |
| position | string | Yes | Position |
| player_name | string | Yes | Player name |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, nhl_player_id)`
- Index: `nhl_game_id`
- Index: `nhl_player_id`
- Index: `nhl_team_id`

---

## nhl_game_summaries

**Organization-owned:** No
**Purpose:** Derived per-player per-game NHL stat summaries from play-by-play.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | bigint | No | FK -> nhl_games.nhl_game_id (CASCADE) |
| nhl_player_id | bigint | No | FK -> players.nhl_id (CASCADE) |
| nhl_team_id | unsignedInteger | No | NHL team ID |
| g | unsignedSmallInteger | No | Goals; default `0` |
| evg | unsignedSmallInteger | No | Even-strength goals; default `0` |
| a | unsignedSmallInteger | No | Assists; default `0` |
| eva | unsignedSmallInteger | No | Even-strength assists; default `0` |
| a1 | unsignedSmallInteger | No | Primary assists; default `0` |
| eva1 | unsignedSmallInteger | No | Even-strength primary assists; default `0` |
| a2 | unsignedSmallInteger | No | Secondary assists; default `0` |
| eva2 | unsignedSmallInteger | No | Even-strength secondary assists; default `0` |
| pts | unsignedSmallInteger | No | Points; default `0` |
| evpts | unsignedSmallInteger | No | Even-strength points; default `0` |
| gwg | unsignedTinyInteger | No | Game-winning goals; default `0` |
| otg | unsignedTinyInteger | No | Overtime goals; default `0` |
| ota | unsignedTinyInteger | No | Overtime assists; default `0` |
| shog | unsignedSmallInteger | No | Shootout goals; default `0` |
| shogwg | unsignedTinyInteger | No | Shootout game-winning goals; default `0` |
| ps | unsignedSmallInteger | No | Penalty shots; default `0` |
| psg | unsignedSmallInteger | No | Penalty shot goals; default `0` |
| ens | unsignedSmallInteger | No | Empty-net shots/events; default `0` |
| eng | unsignedSmallInteger | No | Empty-net goals; default `0` |
| fg | unsignedTinyInteger | No | First goals; default `0` |
| htk | unsignedTinyInteger | No | Hat trick flag/count; default `0` |
| plus_minus | smallInteger | No | Plus/minus; default `0` |
| pim | unsignedSmallInteger | No | Penalty minutes; default `0` |
| f | unsignedSmallInteger | No | Faceoffs/events; default `0` |
| toi | unsignedInteger | Yes | Time on ice in seconds |
| shifts | unsignedSmallInteger | No | Shift count; default `0` |
| ppg | unsignedSmallInteger | No | Power-play goals; default `0` |
| ppa | unsignedSmallInteger | No | Power-play assists; default `0` |
| ppa1 | unsignedSmallInteger | No | Power-play primary assists; default `0` |
| ppa2 | unsignedSmallInteger | No | Power-play secondary assists; default `0` |
| ppp | unsignedSmallInteger | No | Power-play points; default `0` |
| pkg | unsignedSmallInteger | No | Penalty-kill goals; default `0` |
| pka | unsignedSmallInteger | No | Penalty-kill assists; default `0` |
| pkp | unsignedSmallInteger | No | Penalty-kill points; default `0` |
| b | unsignedSmallInteger | No | Blocks; default `0` |
| b_teammate | unsignedSmallInteger | No | Teammate blocks; default `0` |
| h | unsignedSmallInteger | No | Hits; default `0` |
| th | unsignedSmallInteger | No | Hits taken; default `0` |
| gv | unsignedSmallInteger | No | Giveaways; default `0` |
| tk | unsignedSmallInteger | No | Takeaways; default `0` |
| tkvgv | smallInteger | No | Takeaway minus giveaway; default `0` |
| fow | unsignedSmallInteger | No | Faceoffs won; default `0` |
| fol | unsignedSmallInteger | No | Faceoffs lost; default `0` |
| fot | unsignedSmallInteger | No | Faceoffs total; default `0` |
| fow_percentage | decimal(5,2) | No | Faceoff win percentage; default `0` |
| sog | unsignedSmallInteger | No | Shots on goal; default `0` |
| ppsog | unsignedSmallInteger | No | Power-play shots on goal; default `0` |
| evsog | unsignedSmallInteger | No | Even-strength shots on goal; default `0` |
| pksog | unsignedSmallInteger | No | Penalty-kill shots on goal; default `0` |
| sm | unsignedSmallInteger | No | Shot misses; default `0` |
| ppsm | unsignedSmallInteger | No | Power-play shot misses; default `0` |
| evsm | unsignedSmallInteger | No | Even-strength shot misses; default `0` |
| pksm | unsignedSmallInteger | No | Penalty-kill shot misses; default `0` |
| sb | unsignedSmallInteger | No | Shots blocked; default `0` |
| ppsb | unsignedSmallInteger | No | Power-play shots blocked; default `0` |
| evsb | unsignedSmallInteger | No | Even-strength shots blocked; default `0` |
| pksb | unsignedSmallInteger | No | Penalty-kill shots blocked; default `0` |
| sat | unsignedSmallInteger | No | Shot attempts; default `0` |
| ppsat | unsignedSmallInteger | No | Power-play shot attempts; default `0` |
| evsat | unsignedSmallInteger | No | Even-strength shot attempts; default `0` |
| pksat | unsignedSmallInteger | No | Penalty-kill shot attempts; default `0` |
| sa | unsignedSmallInteger | No | Shots against; default `0` |
| evsa | unsignedSmallInteger | No | Even-strength shots against; default `0` |
| ppsa | unsignedSmallInteger | No | Power-play shots against; default `0` |
| pksa | unsignedSmallInteger | No | Penalty-kill shots against; default `0` |
| sv | unsignedSmallInteger | No | Saves; default `0` |
| evsv | unsignedSmallInteger | No | Even-strength saves; default `0` |
| ppsv | unsignedSmallInteger | No | Power-play saves; default `0` |
| pksv | unsignedSmallInteger | No | Penalty-kill saves; default `0` |
| shosv | unsignedSmallInteger | No | Shootout saves; default `0` |
| so | unsignedSmallInteger | No | Shutouts; default `0` |
| ga | unsignedSmallInteger | No | Goals against; default `0` |
| evga | unsignedSmallInteger | No | Even-strength goals against; default `0` |
| ppga | unsignedSmallInteger | No | Power-play goals against; default `0` |
| pkga | unsignedSmallInteger | No | Penalty-kill goals against; default `0` |
| sog_p | decimal(6,3) | No | Shot percentage metric; default `0` |
| ppsog_p | decimal(6,3) | No | Power-play shot percentage metric; default `0` |
| evsog_p | decimal(6,3) | No | Even-strength shot percentage metric; default `0` |
| pksog_p | decimal(6,3) | No | Penalty-kill shot percentage metric; default `0` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, nhl_player_id)`
- Index: `(nhl_player_id, nhl_team_id)`
- Implicit (FK index): `nhl_game_id`
- Implicit (FK index): `nhl_player_id`

---

## nhl_games

**Organization-owned:** No
**Purpose:** NHL schedule/game metadata imported from NHL APIs.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| nhl_game_id | unsignedBigInteger | No | Primary key; source NHL game ID |
| season_id | string | No | NHL season ID |
| game_type | integer | No | NHL game type |
| game_date | date | No | Game date |
| game_dow | string | No | Day of week |
| game_month | string | No | Month label |
| venue | string | Yes | Venue name |
| venue_location | string | Yes | Venue location |
| start_time_utc | timestamp | Yes | UTC start time |
| eastern_utc_offset | string(6) | Yes | Eastern UTC offset |
| venue_utc_offset | string(6) | Yes | Venue UTC offset |
| shootout_in_use | boolean | No | Defaults to `false` |
| ot_in_use | boolean | No | Defaults to `false` |
| game_state | string(20) | Yes | NHL game state |
| game_schedule_state | string(20) | Yes | NHL schedule state |
| current_period | integer | Yes | Current period |
| period_type | string(10) | Yes | Period type |
| max_regulation_periods | integer | Yes | Max regulation periods |
| clock_time_remaining | string(20) | Yes | Clock text |
| clock_seconds_remaining | string(20) | Yes | Clock seconds text |
| clock_running | string(20) | Yes | Clock running flag text |
| clock_in_intermission | string(20) | Yes | Intermission flag text |
| clock_display_period | string(20) | Yes | Display period |
| clock_max_periods | string(20) | Yes | Clock max periods |
| tv_broadcasts | json | Yes | Broadcast metadata |
| game_outcome | json | Yes | Outcome metadata |
| home_team_id | bigint | Yes | Home NHL team ID |
| home_team_common_name | string | Yes | Home team common name |
| home_team_abbrev | string(10) | Yes | Home team abbreviation |
| home_team_score | integer | Yes | Home score |
| home_team_sog | integer | Yes | Home shots on goal |
| home_team_logo | string | Yes | Home logo |
| home_team_dark_logo | string | Yes | Home dark logo |
| home_team_place_name | string | Yes | Home place name |
| away_team_id | bigint | Yes | Away NHL team ID |
| away_team_common_name | string | Yes | Away team common name |
| away_team_abbrev | string(10) | Yes | Away team abbreviation |
| away_team_score | integer | Yes | Away score |
| away_team_sog | integer | Yes | Away shots on goal |
| away_team_logo | string | Yes | Away logo |
| away_team_dark_logo | string | Yes | Away dark logo |
| away_team_place_name | string | Yes | Away place name |
| limited_scoring | boolean | No | Defaults to `false` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `nhl_game_id`
- Index: `season_id`
- Index: `game_date`
- Index: `home_team_id`
- Index: `away_team_id`

---

## nhl_import_progress

**Organization-owned:** No
**Purpose:** Import pipeline state for each NHL game and import stage.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| season_id | string(8) | No | NHL season ID |
| game_date | date | No | Game date |
| game_id | string(10) | No | NHL game ID as string |
| game_type | unsignedTinyInteger | Yes | NHL game type |
| import_type | enum | No | `pbp`, `summary`, `shifts`, `boxscore`, `shift-units`, `connect-events`, `sum-game-units` |
| items_count | unsignedInteger | No | Defaults to `0` |
| status | enum | No | `scheduled`, `running`, `error`, `completed`; defaults to `scheduled` |
| discovered_at | timestamp | Yes | Discovery timestamp |
| last_error | text | Yes | Last import error |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(game_id, import_type)`
- Index: `(season_id, game_date)`
- Index: `status`
- Index: `game_type`
- Index: `(season_id, game_type)`

### Behavioral Notes

- `NhlImportOrchestrator` advances game imports in order: play-by-play -> summary -> shifts -> boxscore -> shift units -> event connections -> game unit summaries.

---

## nhl_season_stats

**Organization-owned:** No
**Purpose:** Aggregated per-player NHL season statistics by season and game type.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| season_id | string(9) | No | NHL season ID |
| nhl_player_id | bigint | No | FK -> players.nhl_id (CASCADE) |
| nhl_team_id | unsignedInteger | No | NHL team ID |
| gp | unsignedSmallInteger | No | Games played; default `0` |
| game_type | unsignedSmallInteger | No | Game type; default `0` |
| g | unsignedSmallInteger | No | Goals; default `0` |
| evg | unsignedSmallInteger | No | Even-strength goals; default `0` |
| a | unsignedSmallInteger | No | Assists; default `0` |
| eva | unsignedSmallInteger | No | Even-strength assists; default `0` |
| a1 | unsignedSmallInteger | No | Primary assists; default `0` |
| eva1 | unsignedSmallInteger | No | Even-strength primary assists; default `0` |
| a2 | unsignedSmallInteger | No | Secondary assists; default `0` |
| eva2 | unsignedSmallInteger | No | Even-strength secondary assists; default `0` |
| pts | unsignedSmallInteger | No | Points; default `0` |
| evpts | unsignedSmallInteger | No | Even-strength points; default `0` |
| plus_minus | smallInteger | No | Plus/minus; default `0` |
| gwg | unsignedTinyInteger | No | Game-winning goals; default `0` |
| otg | unsignedTinyInteger | No | Overtime goals; default `0` |
| ota | unsignedTinyInteger | No | Overtime assists; default `0` |
| shog | unsignedSmallInteger | No | Shootout goals; default `0` |
| shogwg | unsignedTinyInteger | No | Shootout game-winning goals; default `0` |
| ps | unsignedSmallInteger | No | Penalty shots; default `0` |
| psg | unsignedSmallInteger | No | Penalty shot goals; default `0` |
| f | unsignedSmallInteger | No | Faceoffs/events; default `0` |
| pim | unsignedSmallInteger | No | Penalty minutes; default `0` |
| ens | unsignedSmallInteger | No | Empty-net shots/events; default `0` |
| eng | unsignedSmallInteger | No | Empty-net goals; default `0` |
| fg | unsignedTinyInteger | No | First goals; default `0` |
| htk | unsignedTinyInteger | No | Hat trick flag/count; default `0` |
| toi | unsignedInteger | Yes | Time on ice in seconds |
| shifts | unsignedSmallInteger | No | Shift count; default `0` |
| ppg | unsignedSmallInteger | No | Power-play goals; default `0` |
| ppa | unsignedSmallInteger | No | Power-play assists; default `0` |
| ppa1 | unsignedSmallInteger | No | Power-play primary assists; default `0` |
| ppa2 | unsignedSmallInteger | No | Power-play secondary assists; default `0` |
| ppp | unsignedSmallInteger | No | Power-play points; default `0` |
| pkg | unsignedSmallInteger | No | Penalty-kill goals; default `0` |
| pka | unsignedSmallInteger | No | Penalty-kill assists; default `0` |
| pkp | unsignedSmallInteger | No | Penalty-kill points; default `0` |
| b | unsignedSmallInteger | No | Blocks; default `0` |
| b_teammate | unsignedSmallInteger | No | Teammate blocks; default `0` |
| h | unsignedSmallInteger | No | Hits; default `0` |
| th | unsignedSmallInteger | No | Hits taken; default `0` |
| gv | unsignedSmallInteger | No | Giveaways; default `0` |
| tk | unsignedSmallInteger | No | Takeaways; default `0` |
| tkvgv | smallInteger | No | Takeaway minus giveaway; default `0` |
| fow | unsignedSmallInteger | No | Faceoffs won; default `0` |
| fol | unsignedSmallInteger | No | Faceoffs lost; default `0` |
| fot | unsignedSmallInteger | No | Faceoffs total; default `0` |
| fow_percentage | decimal(5,2) | No | Faceoff win percentage; default `0` |
| sog | unsignedSmallInteger | No | Shots on goal; default `0` |
| ppsog | unsignedSmallInteger | No | Power-play shots on goal; default `0` |
| evsog | unsignedSmallInteger | No | Even-strength shots on goal; default `0` |
| pksog | unsignedSmallInteger | No | Penalty-kill shots on goal; default `0` |
| sm | unsignedSmallInteger | No | Shot misses; default `0` |
| ppsm | unsignedSmallInteger | No | Power-play shot misses; default `0` |
| evsm | unsignedSmallInteger | No | Even-strength shot misses; default `0` |
| pksm | unsignedSmallInteger | No | Penalty-kill shot misses; default `0` |
| sb | unsignedSmallInteger | No | Shots blocked; default `0` |
| ppsb | unsignedSmallInteger | No | Power-play shots blocked; default `0` |
| evsb | unsignedSmallInteger | No | Even-strength shots blocked; default `0` |
| pksb | unsignedSmallInteger | No | Penalty-kill shots blocked; default `0` |
| sat | unsignedSmallInteger | No | Shot attempts; default `0` |
| ppsat | unsignedSmallInteger | No | Power-play shot attempts; default `0` |
| evsat | unsignedSmallInteger | No | Even-strength shot attempts; default `0` |
| pksat | unsignedSmallInteger | No | Penalty-kill shot attempts; default `0` |
| sa | unsignedSmallInteger | No | Shots against; default `0` |
| evsa | unsignedSmallInteger | No | Even-strength shots against; default `0` |
| ppsa | unsignedSmallInteger | No | Power-play shots against; default `0` |
| pksa | unsignedSmallInteger | No | Penalty-kill shots against; default `0` |
| sv | unsignedSmallInteger | No | Saves; default `0` |
| evsv | unsignedSmallInteger | No | Even-strength saves; default `0` |
| ppsv | unsignedSmallInteger | No | Power-play saves; default `0` |
| pksv | unsignedSmallInteger | No | Penalty-kill saves; default `0` |
| ga | unsignedSmallInteger | No | Goals against; default `0` |
| evga | unsignedSmallInteger | No | Even-strength goals against; default `0` |
| ppga | unsignedSmallInteger | No | Power-play goals against; default `0` |
| pkga | unsignedSmallInteger | No | Penalty-kill goals against; default `0` |
| shosv | unsignedSmallInteger | No | Shootout saves; default `0` |
| so | unsignedSmallInteger | No | Shutouts; default `0` |
| sog_p | decimal(6,3) | No | Shot percentage metric; default `0` |
| ppsog_p | decimal(6,3) | No | Power-play shot percentage metric; default `0` |
| evsog_p | decimal(6,3) | No | Even-strength shot percentage metric; default `0` |
| pksog_p | decimal(6,3) | No | Penalty-kill shot percentage metric; default `0` |
| sat_p | decimal(6,3) | No | Shot-attempt percentage metric; default `0` |
| ppsat_p | decimal(6,3) | No | Power-play shot-attempt percentage metric; default `0` |
| evsat_p | decimal(6,3) | No | Even-strength shot-attempt percentage metric; default `0` |
| pksat_p | decimal(6,3) | No | Penalty-kill shot-attempt percentage metric; default `0` |
| g_pg | decimal(6,3) | No | Goals per game; default `0` |
| a_pg | decimal(6,3) | No | Assists per game; default `0` |
| pts_pg | decimal(6,3) | No | Points per game; default `0` |
| b_pg | decimal(6,3) | No | Blocks per game; default `0` |
| h_pg | decimal(6,3) | No | Hits per game; default `0` |
| th_pg | decimal(6,3) | No | Hits taken per game; default `0` |
| g_p60 | decimal(6,3) | No | Goals per 60; default `0` |
| a_p60 | decimal(6,3) | No | Assists per 60; default `0` |
| pts_p60 | decimal(6,3) | No | Points per 60; default `0` |
| sog_p60 | decimal(6,3) | No | Shots on goal per 60; default `0` |
| sat_p60 | decimal(6,3) | No | Shot attempts per 60; default `0` |
| hits_p60 | decimal(6,3) | No | Hits per 60; default `0` |
| blocks_p60 | decimal(6,3) | No | Blocks per 60; default `0` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(season_id, nhl_player_id, game_type)`
- Index: `(season_id, nhl_player_id)`
- Index: `(nhl_player_id, season_id)`
- Index: `(season_id, game_type)`
- Implicit (FK index): `nhl_player_id`

---

## nhl_shifts

**Organization-owned:** No
**Purpose:** Raw per-player NHL shift records imported for each game.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | unsignedBigInteger | No | FK -> nhl_games.nhl_game_id (CASCADE) |
| nhl_player_id | unsignedBigInteger | Yes | NHL player ID |
| shift_number | integer | No | Shift number |
| period | integer | No | Period |
| start_time | string | No | Raw start time |
| end_time | string | No | Raw end time |
| duration | string | Yes | Raw duration string |
| shift_start_seconds | integer | No | Start time in game seconds |
| shift_end_seconds | integer | No | End time in game seconds |
| shift_duration_seconds | integer | Yes | Shift duration in seconds |
| pos_type | string | Yes | Position type |
| position | string | Yes | Position |
| team_abbrev | string | No | Team abbreviation |
| team_name | string | No | Team name |
| first_name | string | No | Player first name |
| last_name | string | No | Player last name |
| detail_code | string | Yes | Source detail code |
| event_description | string | Yes | Event description |
| event_details | string | Yes | Event details |
| event_number | string | Yes | Event number |
| type_code | integer | Yes | Source type code |
| hex_value | string | Yes | Source hex value |
| unit_id | bigint | Yes | FK -> nhl_units.id (SET NULL) |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `nhl_game_id`
- Index: `nhl_player_id`
- Index: `unit_id`
- Implicit (FK index): `unit_id`

### Notes

- The down migration currently calls `Schema::dropIfExists('shifts')`; migrations remain the source of truth, but the created table is `nhl_shifts`.

---

## nhl_unit_game_summaries

**Organization-owned:** No
**Purpose:** Per-game summary metrics for NHL player units/lines.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | bigint | No | FK -> nhl_games.nhl_game_id (CASCADE) |
| unit_id | bigint | No | FK -> nhl_units.id (CASCADE) |
| team_id | unsignedBigInteger | Yes | NHL team ID |
| team_abbrev | string | Yes | Team abbreviation |
| toi | unsignedInteger | No | Time on ice; default `0` |
| shifts | unsignedSmallInteger | No | Shift count; default `0` |
| ozs | unsignedSmallInteger | No | Offensive-zone starts; default `0` |
| nzs | unsignedSmallInteger | No | Neutral-zone starts; default `0` |
| dzs | unsignedSmallInteger | No | Defensive-zone starts; default `0` |
| gf | unsignedSmallInteger | No | Goals for; default `0` |
| ga | unsignedSmallInteger | No | Goals against; default `0` |
| ev_gf | unsignedSmallInteger | No | Even-strength goals for; default `0` |
| pp_gf | unsignedSmallInteger | No | Power-play goals for; default `0` |
| pk_gf | unsignedSmallInteger | No | Penalty-kill goals for; default `0` |
| ev_ga | unsignedSmallInteger | No | Even-strength goals against; default `0` |
| pp_ga | unsignedSmallInteger | No | Power-play goals against; default `0` |
| pk_ga | unsignedSmallInteger | No | Penalty-kill goals against; default `0` |
| sf | unsignedSmallInteger | No | Shots for; default `0` |
| sa | unsignedSmallInteger | No | Shots against; default `0` |
| ev_sf | unsignedSmallInteger | No | Even-strength shots for; default `0` |
| pp_sf | unsignedSmallInteger | No | Power-play shots for; default `0` |
| pk_sf | unsignedSmallInteger | No | Penalty-kill shots for; default `0` |
| ev_sa | unsignedSmallInteger | No | Even-strength shots against; default `0` |
| pp_sa | unsignedSmallInteger | No | Power-play shots against; default `0` |
| pk_sa | unsignedSmallInteger | No | Penalty-kill shots against; default `0` |
| satf | unsignedSmallInteger | No | Shot attempts for; default `0` |
| sata | unsignedSmallInteger | No | Shot attempts against; default `0` |
| ff | unsignedSmallInteger | No | Fenwick for; default `0` |
| fa | unsignedSmallInteger | No | Fenwick against; default `0` |
| bf | unsignedSmallInteger | No | Blocks for; default `0` |
| ba | unsignedSmallInteger | No | Blocks against; default `0` |
| hf | unsignedSmallInteger | No | Hits for; default `0` |
| ha | unsignedSmallInteger | No | Hits against; default `0` |
| fow | unsignedSmallInteger | No | Faceoffs won; default `0` |
| fol | unsignedSmallInteger | No | Faceoffs lost; default `0` |
| fot | unsignedSmallInteger | No | Faceoffs total; default `0` |
| f | unsignedSmallInteger | No | Faceoffs/events; default `0` |
| pim_f | unsignedSmallInteger | No | Penalty minutes for; default `0` |
| pim_a | unsignedSmallInteger | No | Penalty minutes against; default `0` |
| penalties_f | unsignedSmallInteger | No | Penalties for; default `0` |
| penalties_a | unsignedSmallInteger | No | Penalties against; default `0` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, unit_id)`
- Index: `nhl_game_id`
- Index: `team_id`
- Index: `team_abbrev`
- Implicit (FK index): `unit_id`

---

## nhl_unit_players

**Organization-owned:** No
**Purpose:** Join table between NHL units and players.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| unit_id | bigint | No | FK -> nhl_units.id (CASCADE) |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(unit_id, player_id)`
- Implicit (FK index): `unit_id`
- Implicit (FK index): `player_id`

---

## nhl_unit_shifts

**Organization-owned:** No
**Purpose:** Aggregated shift windows for NHL units/lines during games.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| team_id | unsignedBigInteger | Yes | NHL team ID |
| team_abbrev | string | Yes | Team abbreviation |
| unit_id | bigint | No | FK -> nhl_units.id (CASCADE) |
| nhl_game_id | unsignedBigInteger | No | FK -> nhl_games.nhl_game_id (CASCADE) |
| period | integer | No | Period |
| start_time | string | No | Raw start time |
| end_time | string | Yes | Raw end time |
| start_game_seconds | integer | No | Start time in game seconds |
| end_game_seconds | integer | No | End time in game seconds |
| seconds | integer | No | Duration seconds; default `0` |
| starting_zone | enum | Yes | `O`, `N`, `D` |
| ending_zone | enum | Yes | `O`, `N`, `D` |
| is_faceoff | boolean | Yes | Faceoff flag |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(unit_id, nhl_game_id, start_game_seconds)`
- Index: `team_id`
- Index: `team_abbrev`
- Index: `unit_id`
- Index: `nhl_game_id`
- Implicit (FK index): `unit_id`

---

## nhl_units

**Organization-owned:** No
**Purpose:** NHL player groupings/units, such as forward lines, defensive pairs, goalies, power play, and penalty kill units.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| team_abbrev | string(10) | Yes | Team abbreviation |
| unit_type | enum | No | `F`, `D`, `G`, `PP`, `PK` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `team_abbrev`
- Index: `unit_type`

---

## organization_leagues

**Organization-owned:** Yes
**Purpose:** Links internal leagues to organizations and optional Discord servers.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| league_id | bigint | No | FK -> leagues.id (CASCADE) |
| discord_server_id | bigint | Yes | FK -> discord_servers.id (SET NULL) |
| linked_at | timestamp | Yes | Link timestamp |
| meta | json | Yes | Link metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `league_id` (`uq_league_single_org`)
- Index: `(organization_id, league_id)` (`idx_org_league_lookup`)
- Implicit (FK index): `organization_id`
- Implicit (FK index): `league_id`
- Implicit (FK index): `discord_server_id`

---

## organization_user

**Organization-owned:** Yes
**Purpose:** Organization membership pivot for users.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| settings | json | Yes | Per-membership settings |
| deleted_at | timestamp | Yes | Soft delete timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(organization_id, user_id)`
- Index: `(user_id, organization_id)`
- Implicit (FK index): `organization_id`
- Implicit (FK index): `user_id`

---

## organizations

**Organization-owned:** No
**Purpose:** Fantasy communities/organizations that own community settings, leagues, Discord servers, and provider accounts.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| name | string | No | Organization name |
| short_name | string | Yes | Short display name |
| slug | string | No | Unique URL-safe slug |
| owner_user_id | bigint | Yes | FK -> users.id (SET NULL), unique |
| settings | json | Yes | Organization feature/settings payload |
| deleted_at | timestamp | Yes | Soft delete timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `slug`
- Unique: `owner_user_id`
- Index: `name`
- Implicit (FK index): `owner_user_id`

---

## password_reset_tokens

**Organization-owned:** No
**Purpose:** Laravel password reset tokens.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| email | string | No | Primary key |
| token | string | No | Reset token |
| created_at | timestamp | Yes | Token creation timestamp |

### Keys & Indexes

- PK: `email`

---

## personal_access_tokens

**Organization-owned:** No
**Purpose:** Laravel Sanctum API tokens.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| tokenable_type | string | No | Polymorphic owner type from `morphs('tokenable')` |
| tokenable_id | bigint | No | Polymorphic owner ID from `morphs('tokenable')` |
| name | string | No | Token name |
| token | string(64) | No | Unique token hash |
| abilities | text | Yes | Serialized abilities |
| last_used_at | timestamp | Yes | Last use timestamp |
| expires_at | timestamp | Yes | Expiration timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `token`
- Index: `(tokenable_type, tokenable_id)`

---

## perspectives

**Organization-owned:** Optional
**Purpose:** User/organization-defined stat table perspectives, filters, columns, and sorting settings.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| name | string | No | Perspective name |
| slug | string | No | Unique slug |
| author_id | bigint | Yes | FK -> users.id (SET NULL) |
| organization_id | bigint | Yes | FK -> organizations.id (SET NULL) |
| visibility | enum | No | `private`, `public_authenticated`, `public_guest`; defaults to `private` |
| sport | enum | No | `hockey`, `football`, `basketball`; defaults to `hockey` |
| is_slicable | boolean | No | Defaults to `true`; controls stat slice UI |
| settings | json | Yes | Perspective configuration |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `slug`
- Implicit (FK index): `author_id`
- Implicit (FK index): `organization_id`

---

## platform_leagues

**Organization-owned:** No; linked to organizations through league records
**Purpose:** External fantasy platform leagues, currently Fantrax/Yahoo/ESPN shaped.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform | enum | No | `fantrax`, `yahoo`, `espn` |
| platform_league_id | string | No | External league ID |
| name | string | No | League name |
| sport | string | Yes | Sport key |
| synced_at | timestamp | Yes | Last sync timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform, platform_league_id)` (`uq_platform_league`)
- Index: `platform`

---

## platform_player_ids

**Organization-owned:** No
**Purpose:** Maps canonical players to external platform player IDs.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| platform | enum | No | `fantrax`, `yahoo`, `espn` |
| platform_player_id | string | No | External player ID |
| extras | json | Yes | Platform metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform, platform_player_id)` (`uq_platform_player_external`)
- Unique: `(platform, player_id)` (`uq_platform_player_link`)
- Index: `platform`
- Implicit (FK index): `player_id`

---

## platform_roster_memberships

**Organization-owned:** No; platform-team owned
**Purpose:** Historical/current player roster memberships for external fantasy teams.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_team_id | bigint | No | FK -> platform_teams.id (CASCADE) |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| platform | enum | No | `fantrax`, `yahoo`, `espn` |
| platform_player_id | string | Yes | External player ID |
| slot | string | Yes | Roster slot |
| status | enum | Yes | `active`, `bench`, `ir`, `na`, `taxi` |
| eligibility | json | Yes | Position/slot eligibility |
| starts_at | timestamp | No | Roster period start |
| ends_at | timestamp | Yes | Roster period end; null means current |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_team_id, player_id, starts_at)` (`uq_roster_period_start`)
- Index: `platform`
- Index: `platform_player_id`
- Index: `(platform_team_id, ends_at)` (`idx_team_current`)
- Index: `(player_id, ends_at)` (`idx_player_current`)
- Index: `(platform, platform_player_id)` (`idx_platform_external`)
- Implicit (FK index): `platform_team_id`
- Implicit (FK index): `player_id`

---

## platform_teams

**Organization-owned:** No; platform-league owned
**Purpose:** External fantasy platform teams in platform leagues.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| platform_team_id | string | No | External team ID |
| name | string | No | Team name |
| short_name | string | Yes | Short team name |
| extras | json | Yes | Platform metadata |
| synced_at | timestamp | Yes | Last sync timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, platform_team_id)` (`uq_league_platform_team`)
- Index: `platform_league_id` (`idx_team_league`)

---

## player_imports

**Organization-owned:** No
**Purpose:** Minimal timestamped marker table for player imports.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`

---

## provider_accounts

**Organization-owned:** Yes
**Purpose:** External provider account connections for organizations, including Patreon OAuth/sync state.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| organization_id | bigint | No | FK -> organizations.id (CASCADE) |
| provider | string | No | Provider key |
| external_id | string | Yes | Provider account/campaign ID |
| display_name | string | Yes | Provider display name |
| status | string | No | Defaults to `disconnected` |
| access_token | text | Yes | OAuth access token |
| refresh_token | text | Yes | OAuth refresh token |
| token_expires_at | timestamp | Yes | Token expiry |
| scopes | json | Yes | Granted scopes |
| webhook_secret | string | Yes | Provider webhook secret |
| connected_at | timestamp | Yes | Connection timestamp |
| last_synced_at | timestamp | Yes | Last sync timestamp |
| last_webhook_at | timestamp | Yes | Last webhook timestamp |
| last_sync_error | text | Yes | Last sync error text |
| meta | json | Yes | Provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(organization_id, provider)`
- Implicit (FK index): `organization_id`

---

## player_rankings

**Organization-owned:** Optional through ranking profile
**Purpose:** Player ranking entries within ranking profiles.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| ranking_profile_id | bigint | No | FK -> ranking_profiles.id (CASCADE) |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| score | string | No | Ranking score; indexed |
| description | text | Yes | Ranking note/description |
| visibility | enum | No | `private`, `public_authenticated`, `public_guest`; defaults to `private` |
| sport | enum | No | `hockey`, `football`, `basketball`; defaults to `hockey` |
| settings | json | Yes | Ranking settings |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(ranking_profile_id, player_id)`
- Index: `score`
- Implicit (FK index): `ranking_profile_id`
- Implicit (FK index): `player_id`

---

## players

**Organization-owned:** No
**Purpose:** Canonical hockey player registry used by NHL, Fantrax, contract, ranking, and stats features.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_id | unsignedBigInteger | Yes | Unique NHL player ID |
| nhl_team_id | unsignedBigInteger | Yes | NHL team ID |
| full_name | string | Yes | Full name |
| first_name | string | No | First name |
| last_name | string | No | Last name |
| dob | date | Yes | Date of birth |
| country_code | string | Yes | Country code |
| is_prospect | boolean | No | Defaults to `false` |
| is_goalie | boolean | No | Defaults to `false` |
| position | string | Yes | Position, e.g. `C`, `RW`, `G` |
| pos_type | string | Yes | Position type, e.g. `F`, `D`, `G` |
| team_abbrev | string | Yes | NHL team abbreviation |
| current_league_abbrev | string | Yes | Current league abbreviation |
| shoots | enum | Yes | `R`, `L` |
| height | string | Yes | Height string |
| weight | unsignedSmallInteger | Yes | Weight in pounds |
| head_shot_url | text | Yes | Headshot URL |
| hero_image_url | text | Yes | Hero image URL |
| status | string | No | Defaults to `active` |
| meta | json | Yes | External metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `nhl_id`
- Index: `nhl_id`
- Index: `nhl_team_id`

---

## play_by_plays

**Organization-owned:** No
**Purpose:** Raw NHL play-by-play events with normalized event actor fields and shot geometry.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | bigint | No | NHL game ID; indexed |
| nhl_player_id | unsignedBigInteger | Yes | Primary NHL player ID; indexed |
| event_owner_team_id | integer | Yes | Event owner team ID; indexed |
| period | integer | Yes | Period; indexed |
| time_in_period | string | Yes | Time in period |
| time_remaining | string | Yes | Time remaining |
| seconds_in_period | integer | Yes | Seconds into period |
| seconds_in_game | integer | Yes | Seconds into game |
| seconds_remaining | integer | Yes | Seconds remaining |
| seconds_since_last_event | integer | Yes | Delta from prior event |
| type_desc_key | string | Yes | NHL event type key |
| desc_key | string | Yes | Description key |
| strength | string | Yes | Strength state |
| nhl_event_id | string | Yes | NHL event ID |
| period_type | string | Yes | Period type |
| situation_code | string | Yes | Situation code |
| type_code | integer | Yes | NHL event type code |
| duration | integer | Yes | Event duration |
| penalty_type_code | string | Yes | Penalty type code |
| sort_order | integer | Yes | Event ordering |
| fo_winning_player_id | integer | Yes | Faceoff winner ID; indexed |
| fo_losing_player_id | integer | Yes | Faceoff loser ID; indexed |
| x_coord | integer | Yes | Rink X coordinate |
| y_coord | integer | Yes | Rink Y coordinate |
| home_team_defending_side | string | Yes | Home defending side |
| shot_distance | decimal(8,2) | Yes | Shot distance |
| shot_angle | decimal(7,3) | Yes | Shot angle |
| zone_code | string | Yes | Zone code |
| code_type | string | Yes | Code type |
| scoring_player_id | integer | Yes | Scorer ID; indexed |
| scoring_player_total | integer | No | Defaults to `0` |
| assist1_player_id | integer | Yes | Primary assist ID; indexed |
| assist1_player_total | integer | No | Defaults to `0` |
| assist2_player_id | integer | Yes | Secondary assist ID; indexed |
| assist2_player_total | integer | No | Defaults to `0` |
| committed_by_player_id | integer | Yes | Penalty committed by; indexed |
| drawn_by_player_id | integer | Yes | Penalty drawn by; indexed |
| shot_type | string | Yes | Shot type |
| shooting_player_id | integer | Yes | Shooter ID; indexed |
| goalie_in_net_player_id | integer | Yes | Goalie ID; indexed |
| blocking_player_id | integer | Yes | Blocking player ID; indexed |
| reason | string | Yes | Event reason |
| secondary_reason | string | Yes | Secondary reason |
| hitting_player_id | integer | Yes | Hitter ID; indexed |
| hittee_player_id | integer | Yes | Hittee ID; indexed |
| highlight_clip_sharing_url | string | Yes | Highlight URL |
| highlight_clip_id | unsignedBigInteger | Yes | Highlight clip ID |
| away_score | integer | Yes | Defaults to `0` |
| home_score | integer | Yes | Defaults to `0` |
| metadata | json | Yes | Raw/extra metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `nhl_game_id`
- Index: `nhl_player_id`
- Index: `event_owner_team_id`
- Index: `period`
- Index: `fo_winning_player_id`
- Index: `fo_losing_player_id`
- Index: `scoring_player_id`
- Index: `assist1_player_id`
- Index: `assist2_player_id`
- Index: `committed_by_player_id`
- Index: `drawn_by_player_id`
- Index: `shooting_player_id`
- Index: `goalie_in_net_player_id`
- Index: `blocking_player_id`
- Index: `hitting_player_id`
- Index: `hittee_player_id`

---

## ranking_profiles

**Organization-owned:** Optional
**Purpose:** Ranking sets/profiles owned by users or organizations.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| name | string | No | Profile name |
| description | text | Yes | Profile description |
| author_id | bigint | Yes | FK -> users.id (SET NULL) |
| organization_id | bigint | Yes | FK -> organizations.id (SET NULL) |
| visibility | enum | No | `private`, `public_authenticated`, `public_guest`; defaults to `private` |
| sport | enum | No | `hockey`, `football`, `basketball`; defaults to `hockey` |
| settings | json | Yes | Profile settings |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Implicit (FK index): `author_id`
- Implicit (FK index): `organization_id`

---

## role_user

**Organization-owned:** Optional
**Purpose:** Assigns global or organization-scoped roles to users.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| role_id | bigint | No | FK -> roles.id (CASCADE) |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| organization_id | bigint | Yes | FK -> organizations.id (CASCADE); null for global roles |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(role_id, user_id, organization_id)`
- Index: `(user_id, organization_id)`
- Implicit (FK index): `role_id`
- Implicit (FK index): `user_id`
- Implicit (FK index): `organization_id`

---

## roles

**Organization-owned:** No
**Purpose:** Global and organization-scoped role definitions.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| name | string | No | Unique display name |
| slug | string | No | Unique role slug |
| level | unsignedInteger | No | Permission/role level |
| scope | enum | No | `global`, `organization`; defaults to `organization` |
| is_active | boolean | No | Defaults to `true` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `name`
- Unique: `slug`

---

## season_stats

**Organization-owned:** No
**Purpose:** Legacy/alternate season stat aggregate table.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | No | ForeignId declared without constraint |
| nhl_player_id | integer | Yes | NHL player ID |
| season_id | bigint | No | ForeignId declared without constraint |
| nhl_team_id | bigint | No | ForeignId declared without constraint |
| GP | integer | No | Games played; default `0` |
| G | integer | No | Goals |
| EVG | integer | No | Even-strength goals |
| A | integer | No | Assists |
| EVA | integer | No | Even-strength assists |
| A1 | integer | No | Primary assists |
| EVA1 | integer | No | Even-strength primary assists |
| A2 | integer | No | Secondary assists |
| EVA2 | integer | No | Even-strength secondary assists |
| PTS | integer | No | Points |
| EVPTS | integer | No | Even-strength points |
| PIM | integer | No | Penalty minutes |
| TOI | integer | No | Time on ice |
| SHIFTS | integer | No | Shifts |
| PPG | integer | No | Power-play goals |
| PPA | integer | No | Power-play assists |
| PPA1 | integer | No | Power-play primary assists |
| PPA2 | integer | No | Power-play secondary assists |
| PPP | integer | No | Power-play points |
| SHG | integer | No | Short-handed goals |
| SHA | integer | No | Short-handed assists |
| SHP | integer | No | Short-handed points |
| B | integer | No | Blocks |
| H | integer | No | Hits |
| TH | integer | No | Hits taken |
| GV | integer | No | Giveaways |
| TK | integer | No | Takeaways |
| TKvGV | integer | No | Takeaway minus giveaway |
| FOW | integer | No | Faceoffs won; default `0` |
| FOL | integer | No | Faceoffs lost; default `0` |
| FOT | integer | No | Faceoffs total; default `0` |
| FOW_percentage | float | No | Faceoff win percentage; default `0` |
| SOG | integer | No | Shots on goal |
| PPSOG | integer | No | Power-play shots on goal |
| EVSOG | integer | No | Even-strength shots on goal |
| SM | integer | No | Shot misses |
| PPSM | integer | No | Power-play shot misses |
| EVSM | integer | No | Even-strength shot misses |
| SB | integer | No | Shots blocked |
| PPSB | integer | No | Power-play shots blocked |
| EVSB | integer | No | Even-strength shots blocked |
| SA | integer | No | Shots against |
| PPSA | integer | No | Power-play shots against |
| EVSA | integer | No | Even-strength shots against |
| SOGvSA_p | float | No | Shots on goal vs shots against percentage |
| SOG_p | float | No | Shots on goal percentage |
| PPSOG_p | float | No | Power-play shots percentage |
| EVSOG_p | float | No | Even-strength shots percentage |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`

---

## sessions

**Organization-owned:** No
**Purpose:** Laravel session storage.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | string | No | Primary key |
| user_id | bigint | Yes | Indexed user ID |
| ip_address | string(45) | Yes | IP address |
| user_agent | text | Yes | User agent |
| payload | longText | No | Serialized session payload |
| last_activity | integer | No | Last activity timestamp |

### Keys & Indexes

- PK: `id`
- Index: `user_id`
- Index: `last_activity`

---

## social_accounts

**Organization-owned:** No; user-owned
**Purpose:** OAuth/social provider identities, primarily Discord login/linkage.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| provider | string | No | Provider key |
| provider_user_id | string | No | Provider user ID |
| email | string | Yes | Provider email |
| nickname | string | Yes | Provider nickname |
| name | string | Yes | Provider display name |
| avatar | string | Yes | Avatar URL |
| access_token | text | Yes | OAuth token |
| refresh_token | text | Yes | OAuth refresh token |
| expires_at | timestamp | Yes | Token expiry |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(provider, provider_user_id)`
- Implicit (FK index): `user_id`

---

## stats

**Organization-owned:** No
**Purpose:** Imported player season/stat lines used by the player stats UI.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | No | ForeignId declared without constraint |
| is_prospect | boolean | No | Defaults to `false` |
| nhl_team_id | unsignedBigInteger | Yes | NHL team ID |
| nhl_team_abbrev | string | Yes | NHL team abbreviation |
| player_name | string | Yes | Player name snapshot |
| season_id | string | No | Season ID |
| league_abbrev | string | No | League abbreviation |
| team_name | string | No | Team name |
| sequence | integer | Yes | Source sequence |
| game_type_id | integer | Yes | Game type |
| gp | integer | No | Games played; default `0` |
| g | integer | No | Goals; default `0` |
| a | integer | No | Assists; default `0` |
| pts | integer | No | Points; default `0` |
| gwg | integer | Yes | Game-winning goals |
| ppg | integer | Yes | Power-play goals |
| ppp | integer | Yes | Power-play points |
| shg | integer | Yes | Short-handed goals |
| ot_goals | integer | Yes | Overtime goals |
| pim | integer | Yes | Penalty minutes |
| plus_minus | integer | Yes | Plus/minus |
| sog | integer | Yes | Shots on goal |
| shooting_percentage | float | Yes | Shooting percentage |
| avg_toi | string | Yes | Average TOI |
| total_toi | string | Yes | Total TOI |
| toi_minutes | float | Yes | Parsed total TOI minutes |
| g_per_gp | float | No | Goals per game; default `0` |
| a_per_gp | float | No | Assists per game; default `0` |
| pts_per_gp | float | No | Points per game; default `0` |
| sog_per_gp | float | No | Shots per game; default `0` |
| g_per_60 | float | No | Goals per 60; default `0` |
| a_per_60 | float | No | Assists per 60; default `0` |
| pts_per_60 | float | No | Points per 60; default `0` |
| sog_per_60 | float | No | Shots per 60; default `0` |
| wins | integer | Yes | Goalie wins |
| losses | integer | Yes | Goalie losses |
| ot_losses | integer | Yes | Goalie overtime losses |
| shutouts | integer | Yes | Shutouts |
| gaa | float | Yes | Goals against average |
| sv_pct | float | Yes | Save percentage |
| saves | integer | Yes | Saves |
| shots_against | integer | Yes | Shots against |
| goals_against | integer | Yes | Goals against |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`

---

## user_preferences

**Organization-owned:** No; user-owned
**Purpose:** Per-user JSON preferences keyed by preference name.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| key | string(128) | No | Preference key |
| value | json | Yes | Preference value |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(user_id, key)`
- Index: `(user_id, key)`
- Implicit (FK index): `user_id`

---

## users

**Organization-owned:** No
**Purpose:** Authentication identities for the application.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| name | string | No | User name |
| email | string | No | Unique email |
| email_verified_at | timestamp | Yes | Email verification timestamp |
| password | string | No | Password hash |
| two_factor_secret | text | Yes | Jetstream/Fortify 2FA secret |
| two_factor_recovery_codes | text | Yes | Jetstream/Fortify recovery codes |
| two_factor_confirmed_at | timestamp | Yes | 2FA confirmation timestamp |
| remember_token | string | Yes | Laravel remember token |
| current_team_id | bigint | Yes | Current team ID; foreignId without constraint |
| profile_photo_path | string(2048) | Yes | Jetstream profile photo path |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `email`

---

**End of DB_SCHEMA**
