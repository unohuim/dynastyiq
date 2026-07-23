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

- analytics_events
- analytics_identity_links
- analytics_sessions
- analytics_visitors
- cache
- cache_locks
- cap_contract_projections
- capwages_players
- contract_seasons
- contracts
- discord_commands
- discord_organizations
- discord_servers
- draft_queue_items
- event_unit_shifts
- failed_jobs
- fantrax_draft_picks
- fantrax_draft_states
- fantrax_players
- fantasy_scoring_category_mappings
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
- nhl_game_import_runs
- nhl_game_source_statuses
- nhl_game_summaries
- nhl_games
- nhl_import_progress
- nhle_league_factors
- nhl_player_transactions
- nhl_season_stats
- nhl_shifts
- nhl_teams
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
- platform_league_roster_slots
- platform_league_user_settings
- platform_player_ids
- platform_roster_memberships
- platform_teams
- platform_transaction_entries
- platform_transactions
- player_external_identities
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
- yahoo_fantasy_connections
- yahoo_players

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

## analytics_visitors

**Organization-owned:** No
**Purpose:** First-party anonymous browser visitor record that may later be linked to an authenticated user.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| anonymous_id | uuid | No | Unique app-owned anonymous visitor identifier |
| user_id | bigint | Yes | FK -> users.id (SET NULL) |
| first_seen_at | timestamp | Yes | First observed analytics request |
| last_seen_at | timestamp | Yes | Most recent analytics request |
| first_path | string(2048) | Yes | First observed page path |
| last_path | string(2048) | Yes | Most recent observed page path |
| ip_hash | string(64) | Yes | HMAC hash of request IP |
| user_agent_hash | string(64) | Yes | HMAC hash of request user agent |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `anonymous_id`
- Index: `ix_analytics_visitors_user_seen` on `(user_id, last_seen_at)`

---

## analytics_sessions

**Organization-owned:** No
**Purpose:** First-party analytics session scoped to a visitor cookie.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| analytics_visitor_id | bigint | No | FK -> analytics_visitors.id (CASCADE) |
| user_id | bigint | Yes | FK -> users.id (SET NULL) |
| session_uuid | uuid | No | Unique app-owned session identifier |
| started_at | timestamp | No | Session start timestamp |
| last_seen_at | timestamp | Yes | Most recent event timestamp |
| ended_at | timestamp | Yes | Session close timestamp when known |
| engaged_seconds | unsignedInteger | No | Visible-tab heartbeat total |
| landing_path | string(2048) | Yes | First page path in session |
| last_path | string(2048) | Yes | Most recent page path in session |
| referrer | string(2048) | Yes | Session referrer |
| ip_hash | string(64) | Yes | HMAC hash of request IP |
| user_agent_hash | string(64) | Yes | HMAC hash of request user agent |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `session_uuid`
- Index: `ix_analytics_sessions_user_seen` on `(user_id, last_seen_at)`
- Index: `ix_analytics_sessions_visitor_seen` on `(analytics_visitor_id, last_seen_at)`

---

## analytics_events

**Organization-owned:** No
**Purpose:** First-party browser analytics events emitted by Vite-managed UI tracking.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| analytics_visitor_id | bigint | No | FK -> analytics_visitors.id (CASCADE) |
| analytics_session_id | bigint | Yes | FK -> analytics_sessions.id (SET NULL) |
| user_id | bigint | Yes | FK -> users.id (SET NULL) |
| event_name | string(120) | No | Event key emitted by browser tracking |
| path | string(2048) | Yes | Page path for the event |
| referrer | string(2048) | Yes | Browser referrer when provided |
| properties | json | Yes | Small event metadata payload |
| occurred_at | timestamp | No | Browser-supplied or server fallback event time |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `ix_analytics_events_name_time` on `(event_name, occurred_at)`
- Index: `ix_analytics_events_user_time` on `(user_id, occurred_at)`
- Index: `ix_analytics_events_visitor_time` on `(analytics_visitor_id, occurred_at)`

---

## analytics_identity_links

**Organization-owned:** No
**Purpose:** Audit table recording anonymous visitor to authenticated user linking.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| analytics_visitor_id | bigint | No | FK -> analytics_visitors.id (CASCADE) |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| method | string(32) | No | Link method; see `docs/ENUMS.md` |
| linked_at | timestamp | No | Time the identity link was observed |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `uq_analytics_identity_link_user` on `(analytics_visitor_id, user_id)`

---

## cap_contract_projections

**Organization-owned:** No; user-owned planning data
**Purpose:** User-owned projected cap assumptions for rostered players with expired real contracts.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| platform_team_id | bigint | No | FK -> platform_teams.id (CASCADE) |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| season_key | unsigned integer | No | NHL season key, e.g. `20262027` |
| projected_aav | unsigned bigint | No | Projected cap value in whole dollars |
| source | string(24) | No | Projection source; see docs/ENUMS.md |
| basis | string(48) | No | Projection basis; see docs/ENUMS.md |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, platform_team_id, user_id, player_id, season_key)`
- Index: `(platform_league_id, user_id, platform_team_id)`
- Implicit (FK index): `platform_league_id`
- Implicit (FK index): `platform_team_id`
- Implicit (FK index): `user_id`
- Implicit (FK index): `player_id`

---

## capwages_players

**Organization-owned:** No
**Purpose:** CapWages-owned player profile data linked to provider identity records.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_external_identity_id | bigint | Yes | FK -> player_external_identities.id (SET NULL) |
| player_id | bigint | Yes | FK -> players.id (SET NULL) |
| slug | string | No | Unique CapWages player slug |
| name | string | Yes | CapWages display name |
| team | string | Yes | CapWages team name |
| position | string(20) | Yes | CapWages position |
| league_status | string(40) | Yes | CapWages league status |
| nhl_id | unsignedBigInteger | Yes | NHL player id from CapWages |
| jersey_number | unsignedSmallInteger | Yes | Jersey number |
| birth_date | date | Yes | Birth date |
| birth_place | string | Yes | Birth place |
| nationality | string(40) | Yes | Nationality |
| hand | string(40) | Yes | Shoots/catches hand |
| height_imperial | string(40) | Yes | Height label |
| height_cm | unsignedSmallInteger | Yes | Height in centimeters |
| weight_imperial | string(40) | Yes | Weight label |
| weight_kg | unsignedSmallInteger | Yes | Weight in kilograms |
| acquisition_method | string(80) | Yes | Acquisition method |
| acquisition_details | text | Yes | Acquisition details |
| acquisition_year | unsignedSmallInteger | Yes | Acquisition year |
| acquisition_round | unsignedTinyInteger | Yes | Acquisition round |
| acquisition_overall_pick | unsignedSmallInteger | Yes | Acquisition overall pick |
| acquisition_draft_team | string(40) | Yes | Draft team abbreviation |
| elc_signing_age | unsignedTinyInteger | Yes | Entry-level contract signing age |
| waivers_eligibility_age | unsignedTinyInteger | Yes | Waivers eligibility age |
| api_last_updated | timestamp | Yes | CapWages API last-updated timestamp |
| raw_payload | json | Yes | Raw CapWages detail payload |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `slug`
- Index: `nhl_id`
- Index: `player_external_identity_id`
- Index: `player_id`
- Index: `(league_status, team, position)`
- Implicit (FK index): `player_external_identity_id`
- Implicit (FK index): `player_id`

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

## fantasy_scoring_category_mappings

**Organization-owned:** No
**Purpose:** Platform-neutral scoring category dictionary rows that map provider category labels to DynastyIQ stat columns, formulas, or supportability statuses.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform | string(32) | No | Fantasy platform, e.g. `fantrax` |
| provider_label | string | No | Provider-facing category label |
| definition | text | Yes | Provider category definition |
| alignment_status | string(32) | No | Mapping support status |
| formula | text | Yes | DynastyIQ formula or stat key expression |
| required_schema_columns | json | Yes | DynastyIQ schema/stat columns needed for the mapping |
| unavailable_reason | text | Yes | Reason the category cannot currently be supported |
| notes | text | Yes | Import or product notes |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform, provider_label)` (`uq_fantasy_category_mapping_provider_label`)
- Index: `(platform, alignment_status)` (`ix_fantasy_category_mapping_status`)

---

## draft_queue_items

**Organization-owned:** No; draft/user scoped
**Purpose:** Private ordered manager queue entries for a canonical draft.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| draft_id | bigint | No | FK -> drafts.id (CASCADE) |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| rank | unsignedInteger | No | User-specific queue order within the draft |
| notes | text | Yes | Optional manager notes |
| locked_until | timestamp | Yes | Optional temporary lock window for future auto-pick behavior |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(draft_id, user_id, player_id)` (`uq_draft_queue_items_player`)
- Unique: `(draft_id, user_id, rank)` (`uq_draft_queue_items_rank`)
- Index: `(draft_id, user_id, rank)` (`idx_draft_queue_items_user_rank`)
- Implicit (FK index): `draft_id`
- Implicit (FK index): `user_id`
- Implicit (FK index): `player_id`

---

## fantrax_draft_states

**Organization-owned:** No; platform-league owned
**Purpose:** Current persisted Fantrax draft payload state used to determine polling cadence and pick deltas.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE), unique |
| draft_at | timestamp | Yes | Provider draft datetime when known |
| status | string(32) | No | Current normalized draft status |
| current_draft_pick_count | unsignedInteger | No | Count from Fantrax currentDraftPicks |
| poll_interval_minutes | unsignedSmallInteger | No | Per-league poll interval |
| draft_results_hash | string(64) | Yes | Latest draft results payload hash |
| draft_picks_hash | string(64) | Yes | Latest draft pick info payload hash |
| raw_draft_results | json | Yes | Latest raw draft results payload |
| raw_draft_pick_info | json | Yes | Latest raw draft pick info payload |
| last_checked_at | timestamp | Yes | Last poll/persist time |
| last_detected_pick_at | timestamp | Yes | Last time a new made-pick delta was detected |
| meta | json | Yes | Optional diagnostics |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `platform_league_id` (`uq_fantrax_draft_state_league`)
- Index: `(status, last_checked_at)` (`idx_fantrax_draft_state_status_checked`)
- Implicit (FK index): `platform_league_id`

---

## fantrax_draft_picks

**Organization-owned:** No; platform-league owned
**Purpose:** Current persisted Fantrax draft pick rows used to detect when an unmade pick receives a player id.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| provider_pick_key | string(120) | No | Stable provider-derived pick key |
| overall_pick | unsignedInteger | Yes | Overall pick number when known |
| round | unsignedInteger | Yes | Draft round |
| pick | unsignedInteger | Yes | Provider pick value |
| pick_in_round | unsignedInteger | Yes | Pick number within round |
| fantrax_team_id | string | Yes | Fantrax drafting team id |
| fantrax_player_id | string | Yes | Fantrax player id; null means unmade pick |
| drafted_at | timestamp | Yes | Provider pick timestamp when known |
| detected_at | timestamp | Yes | First local detection time for a made pick |
| announced_at | timestamp | Yes | First local announcement side-effect time for toast/Discord idempotency |
| payload_hash | string(64) | No | Row payload hash |
| raw_payload | json | Yes | Raw provider pick row |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, provider_pick_key)` (`uq_fantrax_draft_pick_provider`)
- Index: `(platform_league_id, overall_pick)` (`idx_fantrax_draft_pick_overall`)
- Index: `(platform_league_id, fantrax_player_id)` (`idx_fantrax_draft_pick_player`)
- Implicit (FK index): `platform_league_id`

---

## yahoo_fantasy_connections

**Organization-owned:** No
**Purpose:** Durable Yahoo Fantasy OAuth grants used by admin-triggered Yahoo Fantasy imports.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| external_id | string | Yes | Yahoo account or proof metadata identifier when available |
| display_name | string | Yes | Display label for the connected Yahoo grant |
| email | string | Yes | Yahoo email when available |
| status | string | No | Connection status, defaults to `connected` |
| access_token | text | No | Encrypted OAuth access token |
| refresh_token | text | Yes | Encrypted OAuth refresh token |
| token_expires_at | timestamp | Yes | OAuth access token expiry |
| scopes | json | Yes | Granted OAuth scopes |
| connected_at | timestamp | Yes | Initial or most recent connection timestamp |
| last_used_at | timestamp | Yes | Most recent API call timestamp |
| last_error | text | Yes | Most recent token/API error text |
| meta | json | Yes | Yahoo proof or provider metadata |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `user_id`
- Index: `status`
- Index: `external_id`
- Index: `token_expires_at`
- Implicit (FK index): `user_id`

---

## yahoo_players

**Organization-owned:** No
**Purpose:** Yahoo Fantasy hockey player records staged before provider identity matching.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_external_identity_id | bigint | Yes | FK -> player_external_identities.id (SET NULL) |
| player_id | bigint | Yes | FK -> players.id (SET NULL) |
| game_key | string(40) | No | Yahoo Fantasy game key, such as `465` |
| player_key | string(120) | No | Unique Yahoo player resource key, such as `465.p.5980` |
| yahoo_player_id | string(80) | No | Yahoo player id within the game |
| full_name | string | Yes | Yahoo full player name |
| first_name | string | Yes | Yahoo first name |
| last_name | string | Yes | Yahoo last name |
| editorial_team_abbr | string(40) | Yes | Yahoo editorial NHL team abbreviation |
| display_position | string(40) | Yes | Yahoo display position string |
| eligible_positions | json | Yes | Yahoo eligible positions |
| raw_payload | json | Yes | Raw Yahoo player XML converted to an auditable payload |
| imported_at | timestamp | Yes | Most recent Yahoo import timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `player_key`
- Unique: `(game_key, yahoo_player_id)`
- Index: `game_key`
- Index: `player_external_identity_id`
- Index: `player_id`
- Index: `(editorial_team_abbr, display_position)`
- Implicit (FK index): `player_external_identity_id`
- Implicit (FK index): `player_id`

---

## import_runs

**Organization-owned:** No
**Purpose:** Lifecycle tracking for admin-triggered import runs by source.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| source | string | No | Import source key |
| status | string | No | Import lifecycle status: `working`, `completed`, or `failed` |
| command | string | Yes | Artisan command name |
| options | json | Yes | Command options payload |
| ran_at | timestamp | No | Legacy run timestamp; initialized at start and updated to terminal timestamp |
| batch_id | string | Yes | Optional batch identifier |
| started_at | timestamp | Yes | Import start timestamp |
| finished_at | timestamp | Yes | Import finish timestamp |
| duration_seconds | integer | Yes | Total runtime in seconds |
| total_records | integer | Yes | Estimated total records for progress UI |
| processed_records | integer | No | Records attempted or processed by the import |
| successful_records | integer | No | Records processed without provider/job exception |
| failed_records | integer | No | Records skipped due to provider or connection failures |
| skipped_records | integer | No | Records skipped due to local import conditions |
| progress_label | string | Yes | User-facing label for the progress unit |
| error_message | text | Yes | Failure message when status is failed |
| meta | json | Yes | Additional import metadata, including `work_batch_id` for child import batches |
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
| archived_at | timestamp | Yes | Binding archive timestamp |
| status | string | Yes | Link status, e.g. `active`, `pending`, `unlinked` |
| meta | json | Yes | Link metadata, including community league draft notification settings and transaction output channel settings |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `(league_id, status, linked_at)` (`ix_league_status_linked`)
- Index: `(platform_league_id, linked_at)` (`ix_pl_linked`)
- Index: `(league_id, platform_league_id, status)` (`ix_lpl_league_platform_status`)
- Index: `(platform_league_id, status)` (`ix_lpl_platform_status`)
- Implicit (FK index): `league_id`
- Implicit (FK index): `platform_league_id`

### Notes

- Active binding uniqueness is enforced by application service logic so provider scope and binding history can be represented without duplicating `platform_leagues`.
- Provider scope metadata, when present, is stored in `meta` with `scope_type`, `scope_key`, and `scope_label`.

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
| is_visible | boolean | No | Defaults to `true`; user-specific Leagues list visibility |
| sort_order | unsigned integer | No | Defaults to `0`; user-specific Leagues list ordering |
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
| shifts | unsignedSmallInteger | No | Shift count; default `0` |
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
| ev_goals_against | unsignedSmallInteger | No | Even-strength goals against; default `0` |
| pp_saves | integer | No | Defaults to `0` |
| pp_shots_against | integer | No | Defaults to `0` |
| pp_goals_against | unsignedSmallInteger | No | Power-play goals against; default `0` |
| pk_saves | integer | No | Defaults to `0` |
| pk_shots_against | integer | No | Defaults to `0` |
| pk_goals_against | unsignedSmallInteger | No | Penalty-kill goals against; default `0` |
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
| run_id | foreignId | Yes | Admin-visible NHL game import run that seeded or owns this stage row |
| season_id | string(8) | No | NHL season ID |
| game_date | date | No | Game date |
| game_id | string(10) | No | NHL game ID as string |
| game_type | unsignedTinyInteger | Yes | NHL game type |
| import_type | enum | No | `pbp`, `summary`, `boxscore`, `shifts`, `shift-units`, `connect-events`, `html-pbp-verify`, `sum-game-units`, `validate-summary` |
| items_count | unsignedInteger | No | Defaults to `0` |
| status | enum | No | `scheduled`, `running`, `error`, `completed`, `skipped`; defaults to `scheduled` |
| discovered_at | timestamp | Yes | Discovery timestamp |
| last_error | text | Yes | Last import error |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(game_id, import_type)`
- FK: `run_id` -> `nhl_game_import_runs.id` (`nullOnDelete`)
- Index: `(run_id, status)`
- Index: `(run_id, game_date)`
- Index: `(season_id, game_date)`
- Index: `status`
- Index: `game_type`
- Index: `(season_id, game_type)`

### Behavioral Notes

- `NhlImportOrchestrator` advances game imports in order: play-by-play -> summary -> boxscore -> shifts -> shift units -> event connections -> HTML PBP verification -> game unit summaries -> validation.
- New scheduled rows created by admin or CLI discovery are linked to `nhl_game_import_runs` through `run_id`; null `run_id` rows remain legacy-compatible and are read by date range.

---

## nhle_league_factors

**Organization-owned:** No
**Purpose:** Versioned NHLe translation factors for external league scoring equivalency.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| source | string(64) | No | Source slug, e.g. `nl_ice_data` |
| source_version | string(16) | No | Source version year, e.g. `2026` |
| model_name | string(120) | No | Source model name |
| model_window | string(120) | No | Seasons included in source model |
| source_league_name | string(120) | No | League name exactly as used by the source |
| mapped_league_codes | json | Yes | DynastyIQ/import league code mappings |
| points_factor | decimal(5,2) | No | Points NHLe factor |
| win_shares_factor | decimal(5,2) | No | Win Shares NHLe factor |
| source_url | string(500) | No | Source article/data URL |
| notes | text | Yes | Source or mapping notes |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(source, source_version, source_league_name)`
- Index: `(source, source_version)`

---

## nhl_game_source_statuses

**Organization-owned:** No
**Purpose:** Provider source availability status for NHL game import preflight checks.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | unsignedBigInteger | No | NHL game ID |
| source | string(32) | No | `pbp`, `boxscore`, `shifts`, `right-rail`, or `html-pbp` |
| status | string(32) | No | `available`, `empty`, or `unavailable` |
| reason | string(120) | Yes | Source-specific block reason |
| url | text | No | Exact provider URL checked |
| details | json | Yes | Source-specific counts or error metadata |
| checked_at | timestamp | Yes | Last source check time |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, source)`
- Index: `(status, source)`
- Index: `(nhl_game_id, status)`

### Behavioral Notes

- PBP, boxscore, and shiftchart source rows are refreshed before the PBP stage is claimed.
- PBP or boxscore rows that are not `available` skip all scheduled/running pipeline stages for that game.
- Shiftchart rows that are not `available` skip only shift-derived on-ice stages.
- Rows intentionally do not foreign-key to `nhl_games` because PBP may not have imported the game row yet.

---

## nhl_game_import_runs

**Organization-owned:** No
**Purpose:** Admin-visible NHL game import orchestration requests.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| action | string(24) | No | `discover`, `process` |
| mode | string(24) | No | Date selection mode |
| status | string(24) | No | `queued`, `running`, `completed`, `failed`; defaults to `queued` |
| start_date | date | No | Later inclusive date boundary |
| end_date | date | No | Earlier inclusive date boundary |
| date_count | unsignedInteger | No | Count of inclusive selected dates |
| queued_jobs | unsignedInteger | No | Count of jobs queued directly by the admin request |
| payload | json | Yes | Validated admin request options |
| last_error | text | Yes | Last run-level error, when recorded |
| created_by | bigint | Yes | FK to `users.id` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- FK: `created_by` -> `users.id` (NULL on delete)
- Index: `(action, status)`
- Index: `(start_date, end_date)`
- Index: `created_at`

### Behavioral Notes

- Run rows describe admin-dispatched discovery or processing requests.
- Pipeline stage progress remains sourced from `nhl_import_progress`.

---

## nhl_game_validations

**Organization-owned:** No
**Purpose:** Persist validation state for computed NHL game artifacts.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | bigint | No | FK to `nhl_games.nhl_game_id` |
| validation_type | string | No | `summary_boxscore` or `pbp_html_report` |
| status | enum | No | `approved`, `failed`, `accepted_exception`, `incomplete`, `invalidated`, `shiftchart-mismatch` |
| mismatch_count | unsignedInteger | No | Count of persisted blocking deltas |
| checked_at | timestamp | Yes | Validation execution timestamp |
| approved_at | timestamp | Yes | Approval or exception timestamp |
| approved_by | bigint | Yes | FK to `users.id` |
| resolution | string(64) | Yes | Admin resolution for reviewable validation records |
| resolution_note | text | Yes | Optional admin resolution note |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, validation_type)`
- Index: `(status, checked_at)`

---

## nhl_game_validation_deltas

**Organization-owned:** No
**Purpose:** Persist player and field-level deltas for failed NHL game validations.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| validation_id | bigint | No | FK to `nhl_game_validations.id` |
| nhl_player_id | bigint | Yes | NHL provider player ID |
| field | string | No | Compared boxscore-side field name |
| boxscore_value | string | Yes | Official value snapshot |
| summary_value | string | Yes | Computed value snapshot |
| delta | decimal(12,3) | Yes | Computed minus official value |
| severity | enum | No | `error`, `warning`; defaults to `error` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Index: `nhl_player_id`
- Index: `(validation_id, nhl_player_id)`

---

## nhl_pbp_source_mismatches

**Organization-owned:** No
**Purpose:** Persist event-level differences between imported API PBP and official NHL HTML PBP report rows.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| validation_id | bigint | No | FK to `nhl_game_validations.id` |
| play_by_play_id | bigint | Yes | FK to `play_by_plays.id` |
| nhl_event_id | string | Yes | NHL event identifier from API or HTML row |
| mismatch_type | string(64) | No | Event count, event field, on-ice, parser, or source availability mismatch type |
| severity | string(16) | No | `high`, `medium`, `low`, or `info` |
| period | unsignedTinyInteger | Yes | Event period when known |
| time_in_period | string(16) | Yes | Event clock when known |
| source_url | string(500) | Yes | Official HTML source URL |
| api_event | json | Yes | Comparable API PBP snapshot |
| html_event | json | Yes | Parsed HTML PBP snapshot |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- FK: `validation_id` -> `nhl_game_validations.id` (`cascadeOnDelete`)
- FK: `play_by_play_id` -> `play_by_plays.id` (`nullOnDelete`)
- Index: `nhl_event_id`
- Index: `mismatch_type`
- Index: `severity`
- Index: `(validation_id, severity)`

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
| wins | unsignedSmallInteger | No | Total goalie wins; default `0` |
| losses | unsignedSmallInteger | No | Regulation goalie losses; default `0` |
| ot_losses | unsignedSmallInteger | No | Overtime goalie losses; default `0` |
| overtime_wins | unsignedSmallInteger | No | Overtime goalie wins; default `0` |
| shootout_wins | unsignedSmallInteger | No | Shootout goalie wins; default `0` |
| shootout_losses | unsignedSmallInteger | No | Shootout goalie losses; default `0` |
| starts | unsignedSmallInteger | No | Goalie starts; default `0` |
| relief_appearances | unsignedSmallInteger | No | Goalie relief appearances; default `0` |
| quality_starts | unsignedSmallInteger | No | Quality starts; default `0` |
| really_bad_starts | unsignedSmallInteger | No | Really bad starts; default `0` |
| quality_start_percentage | decimal(6,3) | No | Quality-start percentage; default `0` |
| sv_pct | decimal(6,3) | No | Save percentage; default `0` |
| gaa | decimal(6,3) | No | Goals-against average; default `0` |
| ev_sv_pct | decimal(6,3) | No | Even-strength save percentage; default `0` |
| pp_sv_pct | decimal(6,3) | No | Power-play save percentage; default `0` |
| pk_sv_pct | decimal(6,3) | No | Penalty-kill save percentage; default `0` |
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

## nhl_teams

**Organization-owned:** No
**Purpose:** NHL-owned team reference data used to normalize provider team strings to NHL abbreviations.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_id | unsignedInteger | No | Unique NHL team id |
| abbrev | string(10) | No | Unique NHL team abbreviation |
| full_name | string | Yes | NHL full team name |
| common_name | string | Yes | NHL common/team name |
| place_name | string | Yes | NHL place/city name |
| raw_payload | json | Yes | Raw NHL team reference payload |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `nhl_id`
- Unique: `abbrev`
- Index: `full_name`
- Index: `common_name`
- Index: `place_name`

---

## nhl_player_transactions

**Organization-owned:** No
**Purpose:** Real hockey player movement history sourced from NHL-domain providers.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | Yes | FK -> players.id (SET NULL) |
| player_external_identity_id | bigint | Yes | FK -> player_external_identities.id (SET NULL) |
| source | string(40) | No | Provider source key |
| source_key | string | No | Unique deterministic source key |
| source_transaction_id | string | Yes | Provider transaction id when available |
| transaction_date | date | Yes | Transaction date when available |
| transaction_type | string(80) | Yes | Provider transaction type |
| description | text | Yes | Transaction description |
| from_team | string | Yes | Origin team when cleanly available |
| to_team | string | Yes | Destination team when cleanly available |
| raw_payload | json | Yes | Raw provider transaction payload |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `source_key`
- Index: `player_id`
- Index: `player_external_identity_id`
- Index: `(source, transaction_date)`
- Index: `(source, transaction_type)`
- Implicit (FK index): `player_id`
- Implicit (FK index): `player_external_identity_id`

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

---

## nhl_unit_game_strength_summaries

**Organization-owned:** No
**Purpose:** Strength-specific per-game on-ice totals for NHL units.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | bigint | No | FK -> nhl_games.nhl_game_id |
| unit_id | bigint | No | FK -> nhl_units.id |
| team_id | unsignedBigInteger | Yes | NHL team ID |
| team_abbrev | string(10) | Yes | Team abbreviation |
| strength | enum | No | `EV`, `PP`, `PK` |
| toi | unsignedInteger | No | Time on ice |
| shifts | unsignedSmallInteger | No | Shift count |
| ozs | unsignedSmallInteger | No | Offensive-zone starts |
| nzs | unsignedSmallInteger | No | Neutral-zone starts |
| dzs | unsignedSmallInteger | No | Defensive-zone starts |
| gf | unsignedSmallInteger | No | Goals for |
| ga | unsignedSmallInteger | No | Goals against |
| sf | unsignedSmallInteger | No | Shots for |
| sa | unsignedSmallInteger | No | Shots against |
| satf | unsignedSmallInteger | No | Shot attempts for |
| sata | unsignedSmallInteger | No | Shot attempts against |
| ff | unsignedSmallInteger | No | Fenwick for |
| fa | unsignedSmallInteger | No | Fenwick against |
| bf | unsignedSmallInteger | No | Blocks for |
| ba | unsignedSmallInteger | No | Blocks against |
| hf | unsignedSmallInteger | No | Hits for |
| ha | unsignedSmallInteger | No | Hits against |
| fow | unsignedSmallInteger | No | Faceoffs won |
| fol | unsignedSmallInteger | No | Faceoffs lost |
| fot | unsignedSmallInteger | No | Faceoffs total |
| pim_f | unsignedSmallInteger | No | Penalty minutes for |
| pim_a | unsignedSmallInteger | No | Penalty minutes against |
| penalties_f | unsignedSmallInteger | No | Penalties for |
| penalties_a | unsignedSmallInteger | No | Penalties against |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, unit_id, strength)`
- Index: `(nhl_game_id, strength)`
- Index: `(unit_id, strength)`

---

## nhl_player_game_strength_summaries

**Organization-owned:** No
**Purpose:** Strength-specific per-game on-ice totals for NHL players.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| nhl_game_id | bigint | No | FK -> nhl_games.nhl_game_id |
| player_id | bigint | No | FK -> players.id |
| nhl_player_id | bigint | No | NHL provider player ID |
| team_id | unsignedBigInteger | Yes | NHL team ID |
| team_abbrev | string(10) | Yes | Team abbreviation |
| strength | enum | No | `EV`, `PP`, `PK` |
| toi | unsignedInteger | No | Time on ice |
| shifts | unsignedSmallInteger | No | Shift count |
| gf | unsignedSmallInteger | No | Goals for |
| ga | unsignedSmallInteger | No | Goals against |
| sf | unsignedSmallInteger | No | Shots for |
| sa | unsignedSmallInteger | No | Shots against |
| satf | unsignedSmallInteger | No | Shot attempts for |
| sata | unsignedSmallInteger | No | Shot attempts against |
| ff | unsignedSmallInteger | No | Fenwick for |
| fa | unsignedSmallInteger | No | Fenwick against |
| bf | unsignedSmallInteger | No | Blocks for |
| ba | unsignedSmallInteger | No | Blocks against |
| hf | unsignedSmallInteger | No | Hits for |
| ha | unsignedSmallInteger | No | Hits against |
| fow | unsignedSmallInteger | No | Faceoffs won |
| fol | unsignedSmallInteger | No | Faceoffs lost |
| fot | unsignedSmallInteger | No | Faceoffs total |
| pim_f | unsignedSmallInteger | No | Penalty minutes for |
| pim_a | unsignedSmallInteger | No | Penalty minutes against |
| penalties_f | unsignedSmallInteger | No | Penalties for |
| penalties_a | unsignedSmallInteger | No | Penalties against |
| individual_g | unsignedSmallInteger | No | Individual goals while in this strength |
| individual_a | unsignedSmallInteger | No | Individual assists while in this strength |
| individual_pts | unsignedSmallInteger | No | Individual points while in this strength |
| ipp | decimal(7,4) | No | Individual points percentage |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(nhl_game_id, player_id, strength)`
- Index: `(nhl_player_id, strength)`
- Index: `(nhl_game_id, strength)`
- Index: `team_id`
- Index: `team_abbrev`
- Implicit (FK index): `player_id`

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

## nhl_unit_shift_players

**Organization-owned:** No
**Purpose:** Contextual player-position rows for a specific NHL unit shift.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| unit_shift_id | bigint | No | FK -> nhl_unit_shifts.id (CASCADE) |
| player_id | bigint | No | FK -> players.id (CASCADE) |
| position_code | string | Yes | Contextual unit-shift position code from imported/enriched source; see `docs/ENUMS.md` |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(unit_shift_id, player_id)`
- Index: `position_code`
- Implicit (FK index): `unit_shift_id`
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
| composition_hash | string(64) | Yes | Deterministic hash of unit type and sorted player composition |
| composition_player_ids | json | Yes | Sorted NHL player ids used for the composition hash |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(team_abbrev, unit_type, composition_hash)` for new resolved units
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
| logo_url | string | Yes | Provider league logo URL when exposed |
| settings | json | Yes | Shared platform league settings managed by commissioner or league admin authority, including `custom_cap`, legacy `salary_cap`, `cap_limits_by_season`, `cap_adjustments_by_team`, active buyout/retention limits, and league-scoped Fantrax contract code definitions for custom salary leagues |
| scoring_settings | json | Yes | Provider scoring metadata, including normalized scoring type, category rows/manual mappings fallback, and raw provider scoring payload |
| synced_at | timestamp | Yes | Last sync timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform, platform_league_id)` (`uq_platform_league`)
- Index: `platform`

---

## platform_league_roster_slots

**Organization-owned:** No; platform-league owned
**Purpose:** Provider-neutral roster slot settings for external fantasy platform leagues.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| slot | string | No | Provider roster slot label |
| slot_type | string | Yes | Normalized slot display group |
| position_type | string | Yes | Normalized hockey position type |
| count | unsignedSmallInteger | No | Number of configured slots |
| sort_order | unsignedSmallInteger | No | Provider roster display order |
| raw_payload | json | Yes | Raw provider roster slot payload |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, slot)` (`uq_platform_league_roster_slot`)
- Index: `(platform_league_id, sort_order)` (`idx_platform_league_roster_order`)
- Implicit (FK index): `platform_league_id`

---

## platform_league_scoring_categories

**Organization-owned:** No; platform-league owned
**Purpose:** First-class provider scoring category rows for external fantasy platform leagues, including dictionary alignment, manual mapping, supportability, and raw provider audit context.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| platform | string(32) | No | Fantasy platform, e.g. `fantrax` or `yahoo` |
| provider_identity_key | string | No | Stable normalized provider category identity within the league |
| provider_category_id | string | Yes | Provider category id when available |
| provider_group | string | Yes | Provider category group |
| provider_code | string | Yes | Provider category code |
| provider_short_label | string | Yes | Provider short label |
| provider_label | string | Yes | Provider display label |
| normalized_group | string | Yes | Normalized group used for comparison |
| normalized_short_label | string | Yes | Normalized short label |
| normalized_label | string | Yes | Normalized display label |
| value | decimal(10,4) | Yes | Scoring value or points modifier |
| position_values | json | Yes | Per-position scoring values when provider exposes them |
| dictionary_mapping_id | bigint | Yes | FK -> fantasy_scoring_category_mappings.id (SET NULL) |
| auto_mapping_key | string | Yes | Auto-selected `stat:` or `dictionary:` mapping key |
| manual_mapping_key | string | Yes | User/admin-selected mapping key |
| selected_mapping_key | string | Yes | Selected manual mapping key for UI compatibility |
| stat_key | string | Yes | Resolved DynastyIQ stat key when direct |
| auto_stat_key | string | Yes | Auto-resolved stat key before manual override |
| mapping_source | string(32) | Yes | `auto`, `dictionary`, or `manual` |
| alignment_status | string(32) | Yes | Dictionary support status |
| formula | text | Yes | Formula or direct stat expression |
| required_schema_columns | json | Yes | DynastyIQ schema/stat columns needed for the mapping |
| is_supported | boolean | No | Whether the category can be supported by current DynastyIQ data |
| support_message | text | Yes | User-facing support warning |
| raw_payload | json | Yes | Raw provider category payload |
| sort_order | unsignedInteger | No | Provider/UI display order |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, provider_identity_key)` (`uq_platform_league_scoring_category_identity`)
- Index: `(platform, normalized_group)` (`ix_platform_league_scoring_category_group`)
- Index: `(platform_league_id, sort_order)` (`ix_platform_league_scoring_category_order`)
- Index: `dictionary_mapping_id` (`ix_platform_league_scoring_category_dictionary`)
- Implicit (FK index): `platform_league_id`

---

## platform_league_player_stats

**Organization-owned:** No; platform-league owned
**Purpose:** Provider-earned fantasy player stat totals for external fantasy leagues, preserving league-specific scoring and lineup decisions separately from DynastyIQ NHL source-of-truth stats.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| platform_team_id | bigint | Yes | FK -> platform_teams.id (SET NULL); fantasy team credited by provider when available |
| player_id | bigint | Yes | FK -> players.id (SET NULL); canonical player match when known |
| platform | string(32) | No | Fantasy platform, e.g. `fantrax` |
| provider_identity_key | string | No | Stable provider stat row identity within the league for deterministic upserts |
| platform_player_id | string | Yes | External provider player ID |
| season | string(16) | No | Provider fantasy season key |
| scoring_period | string(64) | Yes | Provider scoring period/week when scoped below season |
| scope | string(32) | No | Provider stat scope, e.g. `season`, `period`, or `active_lineup` |
| stats | json | No | Provider category/stat totals keyed by provider category or normalized mapping key |
| raw_payload | json | Yes | Raw provider stat row payload for audit/debug context |
| synced_at | timestamp | Yes | Last provider sync timestamp for this row |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, provider_identity_key)` (`uq_platform_league_player_stat_identity`)
- Index: `(platform_league_id, season, scope)` (`ix_platform_league_player_stat_scope`)
- Index: `(platform_team_id, season)` (`ix_platform_league_player_stat_team`)
- Index: `(player_id, season)` (`ix_platform_league_player_stat_player`)
- Index: `(platform, platform_player_id)` (`ix_platform_league_player_stat_provider`)
- Implicit (FK index): `platform_league_id`

---

## platform_league_user_settings

**Organization-owned:** No; user-owned fallback settings for platform leagues
**Purpose:** Per-user league settings used when a platform league has no connected commissioner or league admin authority.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| user_id | bigint | No | FK -> users.id (CASCADE) |
| settings | json | Yes | Manager-local league settings fallback |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, user_id)` (`uq_platform_league_user_settings`)
- Implicit (FK index): `platform_league_id`
- Implicit (FK index): `user_id`

---

## platform_transaction_entries

**Organization-owned:** No; platform-transaction owned
**Purpose:** Normalized asset movements within external fantasy platform transactions, preserving direction, player/pick identity, and raw provider row evidence.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_transaction_id | bigint | No | FK -> platform_transactions.id (CASCADE) |
| entry_index | unsignedInteger | No | Stable row order inside the provider transaction group |
| asset_type | string(40) | No | Normalized asset type; see Platform Transaction Entry Asset Type |
| action | string(40) | No | Normalized movement action; see Platform Transaction Entry Action |
| from_platform_team_id | bigint | Yes | FK -> platform_teams.id (SET NULL); team the asset moved from |
| to_platform_team_id | bigint | Yes | FK -> platform_teams.id (SET NULL); team the asset moved to |
| platform_team_id | bigint | Yes | FK -> platform_teams.id (SET NULL); provider-perspective team for claim/drop or lineup entries |
| player_id | bigint | Yes | FK -> players.id (SET NULL); canonical player match when known |
| platform_player_identity_id | bigint | Yes | FK -> platform_player_ids.id (SET NULL); matched provider player identity row when known |
| provider_player_id | string | Yes | Raw provider player id, such as Fantrax scorer id |
| raw_name | string | Yes | Provider display name when unresolved or non-player |
| from_slot | string | Yes | Source lineup/roster slot when applicable |
| to_slot | string | Yes | Destination lineup/roster slot when applicable |
| draft_year | unsignedSmallInteger | Yes | Draft pick year when parsed |
| draft_round | unsignedTinyInteger | Yes | Draft pick round when parsed |
| draft_pick | unsignedSmallInteger | Yes | Draft pick number when parsed |
| draft_original_team_name | string | Yes | Provider display name for original pick owner when present |
| draft_original_team_provider_id | string | Yes | Provider team id for original pick owner when known |
| raw_payload | json | Yes | Raw provider transaction row payload |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_transaction_id, entry_index)` (`uq_platform_transaction_entry_order`)
- Index: `from_platform_team_id` (`ix_platform_transaction_entry_from_team`)
- Index: `to_platform_team_id` (`ix_platform_transaction_entry_to_team`)
- Index: `platform_team_id` (`ix_platform_transaction_entry_team`)
- Index: `player_id` (`ix_platform_transaction_entry_player`)
- Index: `platform_player_identity_id` (`ix_platform_transaction_entry_platform_player`)
- Index: `(asset_type, action)` (`ix_platform_transaction_entry_asset_action`)
- Implicit (FK index): `platform_transaction_id`

---

## platform_transactions

**Organization-owned:** No; platform-league owned
**Purpose:** External fantasy platform transaction events grouped by provider identity, preserving raw provider evidence separately from current roster state.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| platform_league_id | bigint | No | FK -> platform_leagues.id (CASCADE) |
| platform | string(32) | No | Fantasy platform, e.g. `fantrax` |
| provider_transaction_id | string | Yes | Provider transaction id when available, such as Fantrax `txSetId` |
| source_key | string | No | Deterministic provider-scope idempotency key |
| source_view | string(40) | No | Provider source view; see Platform Transaction Source View |
| transaction_type | string(80) | No | Normalized transaction type; see Platform Transaction Type |
| occurred_at | timestamp | Yes | Provider processed timestamp when parsed |
| period | string(64) | Yes | Provider fantasy period/week when available |
| executed | boolean | Yes | Provider execution state when available |
| deleted | boolean | No | Provider deleted flag; default `false` |
| status | string(80) | Yes | Provider status/result code or label |
| summary | text | Yes | Human-readable normalized transaction summary |
| raw_payload | json | Yes | Raw provider transaction group payload |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(platform_league_id, source_key)` (`uq_platform_transaction_source`)
- Index: `(platform_league_id, source_view, occurred_at)` (`ix_platform_transaction_view_time`)
- Index: `(platform_league_id, transaction_type, occurred_at)` (`ix_platform_transaction_type_time`)
- Index: `provider_transaction_id` (`ix_platform_transaction_provider_id`)
- Implicit (FK index): `platform_league_id`

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
| metadata | json | Yes | Provider roster metadata such as Fantrax custom salary and contract labels |
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
| logo_url | string | Yes | Provider team logo URL when exposed |
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

## player_external_identities

**Organization-owned:** No
**Purpose:** Provider-sourced player identity records used to match external APIs to canonical DynastyIQ players.

### Columns

| Name | Type | Nullable | Notes |
| --- | --- | --- | --- |
| id | bigint | No | Primary key |
| player_id | bigint | Yes | FK -> players.id (SET NULL) |
| provider | string | No | External provider key |
| provider_player_id | string | No | Durable provider player ID |
| provider_slug | string | Yes | Provider slug or URL-safe identifier |
| display_name | string | Yes | Provider display name |
| normalized_name | string | Yes | Normalized matching name |
| first_name | string | Yes | Provider first name |
| last_name | string | Yes | Provider last name |
| birthdate | date | Yes | Provider date of birth |
| position | string | Yes | Provider position |
| team | string | Yes | Provider team abbreviation or label |
| raw_payload | json | Yes | Raw provider payload used for audit/rematching |
| match_status | string | No | Defaults to `unmatched` |
| match_confidence | unsignedTinyInteger | Yes | Resolver confidence from 0 to 100 |
| unmatched_reason | string | Yes | Reason an identity is not matched |
| first_seen_at | timestamp | Yes | First observed timestamp |
| last_seen_at | timestamp | Yes | Most recent observed timestamp |
| created_at | timestamp | Yes | Laravel timestamp |
| updated_at | timestamp | Yes | Laravel timestamp |

### Keys & Indexes

- PK: `id`
- Unique: `(provider, provider_player_id)`
- Index: `player_id`
- Index: `normalized_name`
- Index: `(provider, match_status)`
- Implicit (FK index): `player_id`

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
| draft_year | unsignedSmallInteger | Yes | NHL entry draft year |
| draft_round | unsignedSmallInteger | Yes | NHL entry draft round |
| draft_round_pick | unsignedSmallInteger | Yes | Pick number within NHL entry draft round |
| draft_oa | unsignedSmallInteger | Yes | Overall NHL entry draft pick |
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
- Index: `draft_year`

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
| metadata | json | Yes | Raw provider metadata; NHL imports preserve the source `event` and `details` payloads for audit/debug context |
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
