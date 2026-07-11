# Artisan Command Catalog

This catalog is for operator-facing awareness of first-party Artisan commands in `app/Console/Commands`.

Do not run import, sync, migration, queue, scheduler, or destructive commands from an AI session unless the human explicitly instructs it.

## NHL

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan nhl:discover` | Dispatches jobs | Discover NHL games for a date window and queue per-day discovery jobs. | Options include `--date`, `--season`, `--start`, `--end`, `--days`, and `--newdays`. |
| `php artisan nhl:process` | Dispatches/claims jobs | Supervise run-aware NHL import orchestration for queued discovery runs. | Processes scheduled game stages through the orchestrator. |
| `php artisan nhl:import --players` | Dispatches batch/jobs | Import NHL team player pools, then draft picks. | Supports `--import-run-id` for admin tracking. |
| `php artisan nhl:resolve --players` | Dispatches jobs by default | Resolve canonical players without NHL IDs. | Use `--inline` to run directly instead of queueing resolver jobs. |
| `php artisan nhl:sum --season=20252026` | Dispatches job | Rebuild season aggregates from `nhl_game_summaries`. | Required after changing game summaries that affect season totals. |
| `php artisan nhl:refresh-goalie-decisions --season=20252026 --queue` | Dispatches jobs with `--queue`; runs directly otherwise | Refresh goalie decisions from NHL boxscore goalie rows without reprocessing PBP, shifts, or units. | Also supports `--game-id`, `--date-from`, and `--date-to`; run `nhl:sum` afterward. Use `--queue` for season/window backfills. |
| `php artisan nhl:refresh-special-teams-splits --season=20252026 --queue` | Dispatches jobs with `--queue`; runs directly otherwise | Rebuild PBP-derived game-summary special-teams splits from already imported play-by-play rows. | Skips goalie boxscore reconciliation; also supports `--game-id`, `--date-from`, and `--date-to`; run `nhl:sum` afterward. Use `--queue` for season/window backfills. |
| `php artisan nhl:backfill-shot-geometry` | Runs directly | Compute missing shot distance and angle values for shot-attempt play rows. | Supports `--game-id` and `--chunk`. |
| `php artisan nhl:empty --games` | Runs directly, destructive | Remove NHL game-derived import data. | Preserves canonical players and NHL team reference data. |
| `php artisan nhl:empty --players` | Runs directly, destructive | Remove NHL player stats and NHL/NHL draft player external identities. | Preserves canonical players and game-derived import data. |

## Fantrax

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan fx:sync` | Dispatches jobs | Queue sync jobs for all Fantrax leagues. | Dispatches one `SyncFantraxLeagueJob` per Fantrax league. |
| `php artisan leagues:refresh-connected` | Mixed direct + dispatches jobs | Refresh connected fantasy provider league lists and queue Fantrax league syncs. | Used by scheduler for connected-league refresh. |
| `php artisan fx:import --players` | Dispatches jobs | Import Fantrax player records. | Supports `--import-run-id`; admin-tracked imports queue chunk jobs. |
| `php artisan fantrax:drafts:poll` | Dispatches jobs | Queue draft-state sync jobs for live Fantrax mirrored drafts. | Manual/admin command; dispatches one draft sync job per due league. |
| `php artisan fantrax:import-category-definitions` | Runs directly | Import Fantrax scoring-category dictionary definitions from CSV. | Default path is `docs/import-templates/fantrax_category_alignment.csv`; supports `--import-run-id`. |
| `php artisan fantrax:inspect-logos` | Runs directly | Inspect Fantrax payloads for logo-like keys and image URLs. | Diagnostic command. |
| `php artisan fx:empty` | Runs directly, destructive | Remove Fantrax-owned imported player data. | Does not delete canonical players or league connections. |

## Platform Leagues

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan platform-leagues:backfill-scoring-categories` | Runs directly | Backfill first-class scoring category rows from legacy league JSON. | Supports `--platform` and `--import-run-id`. |
| `php artisan leagues:backfill-commissioners` | Runs directly | Backfill league-scoped commissioner roles for existing community leagues. | Optionally includes organization admins with `--include-org-admins`. |

## CapWages

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan cap:import` | Dispatches jobs | Queue a sequential CapWages player/contract import crawl. | Supports `--per-page`, `--all`, and `--import-run-id`. |
| `php artisan cap:empty` | Runs directly, destructive | Remove CapWages-owned imported data. | Does not delete canonical players. |

## Yahoo

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan yahoo:empty` | Runs directly, destructive | Remove Yahoo-owned imported player data. | Does not delete canonical players or OAuth connections. |

## Patreon

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan patreon:sync-nightly` | Runs directly | Sync Patreon memberships for connected organizations. | Iterates connected Patreon provider accounts. |

## Design And Diagnostics

| Command | Runs | Purpose | Notes |
| --- | --- | --- | --- |
| `php artisan draft:image` | Runs directly | Generate a local preview image for the Fantrax draft pick Discord card. | Default output is under `docs/designs/draft-card-preview/`. |
