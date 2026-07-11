---
pr_id: 13
pr_name: pr13
status: Active
created: 2026-07-10
last_updated: 2026-07-10
---

# Platform League Scoring Categories As First-Class Records

## Source

Fantrax scoring-category alignment work exposed that provider scoring categories are currently persisted inside `platform_leagues.scoring_settings.categories` JSON. Fantrax can send both rich category rows and shorthand scoring rows for the same category, such as `Big Points 3` and `BP3`, which makes deduplication and manual alignment fragile when category identity is only embedded in a JSON array.

The category dictionary import now recognizes many provider-specific Fantrax labels and formulas, and League Options needs to show exactly which provider categories are supported, unsupported, or manually mapped.

## Objective

Move platform league scoring categories from opaque league JSON into queryable child records, with deterministic provider identity, deduplication, dictionary alignment, manual mapping persistence, and stale-row cleanup during sync.

The resulting source of truth should be:

- `platform_league_scoring_categories` for normalized category rows.
- `fantasy_scoring_category_mappings` for provider dictionary definitions.
- Raw provider scoring payload retained only for audit/debug context.

## Current DynastyIQ Context

Current league scoring state is stored under:

- `platform_leagues.scoring_settings.categories`
- `platform_leagues.scoring_settings.manual_mappings`
- `platform_leagues.scoring_settings.raw_payload`

Current code paths include:

- `app/Services/SyncFantraxLeague.php`
- `app/Services/FantraxScoringCategoryMapper.php`
- `app/Services/YahooFantasyLeagueService.php`
- `app/Http/Controllers/LeagueController.php`
- `app/Support/Stats/LeagueStatsPerspectiveFactory.php`
- `resources/views/leagues/_panel.blade.php`

Current dictionary source:

- `app/Models/FantasyScoringCategoryMapping.php`
- `docs/import-templates/fantrax_category_alignment.csv`

## Problems To Solve

- Duplicate provider categories can persist inside league JSON arrays.
- JSON arrays cannot enforce uniqueness for category identity.
- League Options cannot query category rows directly.
- Unsupported provider categories cannot be audited across all connected leagues with SQL.
- Manual mappings are keyed to JSON row IDs instead of durable category records.
- Provider sync replaces a JSON blob, but cannot upsert/delete individual scoring categories deterministically.

## Proposed Schema

Add `platform_league_scoring_categories`.

Candidate columns:

- `id`
- `platform_league_id`
- `platform`
- `provider_category_id`
- `provider_group`
- `provider_code`
- `provider_short_label`
- `provider_label`
- `normalized_group`
- `normalized_short_label`
- `normalized_label`
- `value`
- `position_values`
- `dictionary_mapping_id`
- `auto_mapping_key`
- `manual_mapping_key`
- `stat_key`
- `alignment_status`
- `formula`
- `required_schema_columns`
- `is_supported`
- `support_message`
- `raw_payload`
- `sort_order`
- `created_at`
- `updated_at`

Relationships:

- `PlatformLeague` has many `PlatformLeagueScoringCategory`.
- `PlatformLeagueScoringCategory` belongs to `PlatformLeague`.
- `PlatformLeagueScoringCategory` optionally belongs to `FantasyScoringCategoryMapping`.

## Identity And Dedupe Rules

Provider category sync must normalize group names before identity comparison:

- `SKATING` maps to `HOCKEY_SKATING`.
- `GOALIE` maps to `HOCKEY_GOALIE`.

Provider category sync must prefer rich category labels over shorthand aliases:

- `Big Points 3` over `BP3`
- `Net Faceoffs Won` over `NFOW`
- `Old School Grit 3` over `OSG3`
- `Special Teams Points 2` over `STP2`
- `Goalie Points 4` over `GPT4`

Recommended uniqueness:

- Unique category identity per league using normalized provider identity.
- A second uniqueness guard for provider category IDs when present.

## Sync Behavior

Fantrax sync should:

1. Fetch league info.
2. Normalize rich scoring rows and shorthand scoring rows into a single category collection.
3. Enrich each category from `fantasy_scoring_category_mappings`.
4. Preserve existing manual mapping keys by stable category identity.
5. Upsert normalized category rows.
6. Delete category rows for the league that are no longer present in the provider payload.
7. Persist scoring-system metadata such as provider scoring type, season year, and scoring dates alongside the normalized category rows.
8. Store raw provider scoring payload for audit/debug context only.

The sync should not depend on JSON category rows as the source of truth after migration.

Yahoo sync should follow the same platform-neutral row model, even if its provider payload is simpler.

Fantrax points leagues may compute `Fantasy Pts` and `Fantasy Pts/G` at read time from persisted scoring category weights and supported DynastyIQ stat/formula mappings. Fantrax rotisserie/category leagues must remain category-column views and must not sum category values into a single fantasy-point total.

## Manual Mapping Behavior

Manual scoring alignment should persist on category rows:

- `manual_mapping_key`

Manual mappings may target:

- `stat:<key>`
- `dictionary:<platform>:<provider_label>`
- `custom:<future_formula_id>`

Legacy JSON manual mappings should be backfilled onto matching category rows.

## Read Path

League Options should read scoring alignment categories from `platform_league_scoring_categories`.

Affected paths:

- `LeagueController::scoringCategoriesPayload()`
- `LeagueController::scoringAlignmentCategoriesPayload()`
- `LeagueController::updateScoringSettings()`
- `LeagueStatsPerspectiveFactory`

Temporary fallback to `platform_leagues.scoring_settings.categories` is allowed only for leagues that have not been backfilled yet.

## Backfill

Create a backfill path that reads existing JSON category rows and inserts normalized category records.

Backfill must dedupe existing duplicate rows, preferring richer/dictionary-recognized rows over shorthand rows.

Backfill should preserve:

- provider label
- provider short label
- value
- position values
- dictionary metadata
- support status
- manual mappings
- raw payload

## Documentation Updates

Update:

- `docs/architecture/integrations/FantraxLeagueSync.yaml`
- `docs/architecture/integrations/PlatformCategoryMappingImport.yaml`
- `docs/DB_SCHEMA.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`

Enum storage references should move from JSON category paths to table columns where applicable.

## Testing Scope

Add focused Pest coverage for:

- Fantrax rich and shorthand rows dedupe into one persisted category.
- Manual mappings survive a provider sync.
- Stale category rows are deleted during sync.
- Dictionary alignment metadata is persisted on category rows.
- Unsupported category metadata is preserved for League Options warnings.
- League Options payload reads from category rows.
- JSON fallback works only for leagues without persisted category rows.

No tests, migrations, imports, queue jobs, sync jobs, or seeders should be run by Codex unless explicitly instructed.

## Out Of Scope

- Reworking the stats payload architecture.
- Replacing `fantasy_scoring_category_mappings`.
- Building custom formula authoring UI.
- Removing `platform_leagues.scoring_settings` entirely in the first pass.
- Running production backfills or provider syncs.
