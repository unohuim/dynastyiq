---
pr_id: 18
pr_name: pr18
status: Active
created: 2026-07-22
last_updated: 2026-07-22
---

# Fantrax Platform Transaction Ingestion

## Source

Discussion on 2026-07-22 about replacing screenshot-based trade parsing with
authenticated Fantrax transaction history ingestion.

Relevant Fantrax transaction history views:

- `https://www.fantrax.com/fantasy/league/uf1sdl47mo6nzpr6/transactions/history;view=TRADE`
- `https://www.fantrax.com/fantasy/league/uf1sdl47mo6nzpr6/transactions/history;view=CLAIM_DROP`
- `https://www.fantrax.com/fantasy/league/uf1sdl47mo6nzpr6/transactions/history;view=LINEUP_CHANGE`

The agreed first step is to read those authenticated Fantrax views, populate a
platform-neutral transaction table, and evaluate how accurately DynastyIQ can
extract transaction data before building trade analysis or Discord automation.

## Objective

Add a first-pass Fantrax transaction ingestion workflow that captures provider
transaction history into platform-neutral storage, preserves raw provider
evidence, and normalizes enough structure to inspect extraction quality for
trades, claim/drop events, and lineup changes.

## Current DynastyIQ Context

DynastyIQ already stores platform-neutral Fantrax league, team, roster, scoring,
settings, draft, and player-stat data in tables such as:

- `platform_leagues`
- `platform_teams`
- `platform_player_ids`
- `platform_roster_memberships`
- `platform_league_scoring_categories`
- `platform_league_player_stats`
- `drafts`
- `draft_picks`

`docs/architecture/imports/NhlPlayerTransactions.yaml` explicitly reserves
`nhl_player_transactions` for real hockey movement and says fantasy roster
movement must use a separate fantasy transaction model when introduced.

## Scope

- Discover the Fantrax transaction history network payloads for the `TRADE`,
  `CLAIM_DROP`, and `LINEUP_CHANGE` views.
- Prefer authenticated network JSON payload extraction over DOM parsing.
- Add platform-neutral persistence for fantasy platform transactions.
- Persist raw provider payloads for audit and parser iteration.
- Normalize transaction type, occurred timestamp, source view, source key,
  summary text, involved platform teams, player assets, draft-pick assets when
  detectable, and direction/action rows.
- Resolve Fantrax players to canonical DynastyIQ players where existing
  platform identity data allows it.
- Preserve unresolved names/assets without failing ingestion.
- Add an importer service for one platform league and selected history view.
- Add an operator-facing command or bounded service entry point for manual
  extraction experiments.
- Add a bounded transaction tab inspection surface that refreshes Fantrax history
  and lists recently persisted normalized transactions.
- Add a community league option for a Discord transactions output channel,
  stored for manual-refresh transaction announcements.
- Send Discord announcements for newly created transactions during manual
  refresh when a transactions channel is configured, including rendered trade
  card image attachments for trade transactions and rendered add/drop card
  image attachments for claim/drop transactions. Trade and claim/drop card
  rendering is required for those announcements; rendering failures count as
  Discord announcement failures instead of falling back to text-only messages.
  Trade and claim/drop announcement Discord messages send only the rendered
  card attachment with empty message content.
- Add focused Pest tests for parser normalization, idempotent persistence,
  player/team resolution, unresolved assets, and raw-payload preservation.

## Out Of Scope

- AI trade analysis.
- Discord message listeners, commands, scheduled polling, or bot-driven
  transaction announcement behavior.
- Screenshot OCR or image parsing.
- Scheduled polling.
- Automatic commissioner-machine companion processes.
- Full transaction history product UI beyond the bounded refresh/recent
  transaction inspection surface.
- Updating roster membership state from historical transactions.
- Treating Fantrax transaction history as more authoritative than current roster
  sync for current roster state.
- Claim/drop or lineup-change fantasy-impact analysis.
- Lineup-change image cards.

## Proposed Data Model

Add a platform-neutral transaction model separate from NHL-domain transactions.

Expected transaction row fields:

- `platform_league_id`
- `platform`
- `provider_transaction_id`
- `source_key`
- `transaction_type`
- `source_view`
- `occurred_at`
- `summary`
- `raw_payload`

Expected transaction entry row fields:

- `platform_transaction_id`
- `entry_index`
- `from_platform_team_id`
- `to_platform_team_id`
- `platform_team_id`
- `player_id`
- `platform_player_identity_id`
- `provider_player_id`
- `asset_type`
- `action`
- `from_slot`
- `to_slot`
- `draft_year`
- `draft_round`
- `draft_pick`
- `draft_original_team_name`
- `draft_original_team_provider_id`
- `raw_name`
- `raw_payload`

The first schema pass uses one transaction row per provider transaction group
and one entry row per moved asset. Directional team ids live on entries so
trades, claims, drops, and lineup changes share the same storage shape.

## Service Shape

Expected service boundaries:

- Fantrax authenticated transaction history fetcher.
- Fantrax transaction payload parser.
- Platform transaction persistence service.
- Player/team resolver using existing platform-neutral Fantrax records.

The parser should return normalized DTOs and should not write database rows
directly.

## Processing Rules

- Fantrax transaction imports must be league-scoped.
- Imports must be idempotent by provider transaction id when available, otherwise
  by a deterministic source key derived from provider evidence.
- Raw provider payloads must be preserved for audit and parser refinement.
- Unknown transaction fields must remain in raw payloads rather than being
  discarded.
- Unknown transaction types or views must not be silently coerced into known
  values.
- Player resolution must use existing Fantrax/platform identity data and must not
  create canonical players.
- Unresolved players, picks, or assets must be persisted as unresolved entries
  with raw names/payloads.
- Current roster state must continue to come from roster sync, not from replaying
  historical transaction rows.
- Authenticated Chromium/browser-profile access must remain explicit and
  operator-controlled for this PR.

## Acceptance Criteria

- Running the importer for a Fantrax league and `TRADE` history view stores trade
  transactions and entries with raw provider evidence.
- Running the importer for `CLAIM_DROP` stores add/drop style transactions and
  entries with raw provider evidence.
- Running the importer for `LINEUP_CHANGE` stores lineup movement transactions
  and entries with raw provider evidence.
- Re-running the same import updates existing transactions without duplicating
  rows.
- Resolvable Fantrax player assets link to canonical `players` through existing
  identity data.
- Unresolved player names or non-player assets are retained without failing the
  transaction import.
- Resolvable Fantrax team assets link to `platform_teams`.
- Fantrax transaction team names may resolve to existing `platform_teams` when
  provider transaction rows do not expose usable team ids, preserving access to
  synced team logos for trade cards.
- Parser output can be inspected to judge extraction quality before AI analysis
  or Discord automation is introduced.
- Tests cover successful trade parsing, claim/drop parsing, lineup-change
  parsing, idempotent persistence, player/team resolution, unresolved asset
  persistence, and raw payload retention.

## Documentation Updates

- Add a new architecture YAML under `docs/architecture/integrations/` for the
  platform transaction ingestion pattern.
- Update `docs/ARCHITECTURE_INVENTORY.md` as the derived bootstrap summary.
- Add any new enum-like values to `docs/ENUMS.md`, including transaction type,
  source view, entry asset type, and entry action values if implemented as
  canonical strings.
- Update `docs/DB_SCHEMA.md` if schema documentation is maintained for the new
  tables.
