<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes selected NHL-owned imported data while preserving canonical player records.
 */
class EmptyNhlCommand extends Command
{
    private const GAME_DELETE_CHUNK_SIZE = 5000;

    /**
     * @var string
     */
    protected $signature = 'nhl:empty
        {--players : Remove NHL player stats and external identities}
        {--games : Remove NHL game-derived import data}';

    /**
     * @var string
     */
    protected $description = 'Remove selected NHL-owned imported data without deleting canonical players or team reference data.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $emptyPlayers = (bool) $this->option('players');
        $emptyGames = (bool) $this->option('games');

        if (! $emptyPlayers && ! $emptyGames) {
            $this->error('Choose at least one mode: nhl:empty --players, nhl:empty --games, or both.');

            return self::INVALID;
        }

        if ($emptyGames) {
            $this->emptyGameTables();
        }

        if ($emptyPlayers) {
            $this->emptyPlayerTables();
            $this->emptyPlayerIdentities();
        }

        $this->info($this->successMessage($emptyPlayers, $emptyGames));
        $this->line('Canonical players and NHL team reference data were not deleted.');

        return self::SUCCESS;
    }

    /**
     * Return NHL-owned tables in dependency-safe delete order.
     *
     * @return array<int,string>
     */
    private function gameTables(): array
    {
        return [
            'event_unit_shifts',
            'nhl_unit_game_strength_summaries',
            'nhl_player_game_strength_summaries',
            'nhl_unit_game_summaries',
            'nhl_unit_players',
            'nhl_unit_shifts',
            'nhl_shifts',
            'nhl_units',
            'nhl_game_validation_deltas',
            'nhl_game_validations',
            'nhl_boxscores',
            'nhl_game_summaries',
            'play_by_plays',
            'nhl_season_stats',
            'nhl_game_source_statuses',
            'nhl_games',
        ];
    }

    /**
     * Return NHL import progress tables in dependency-safe delete order.
     *
     * @return array<int,string>
     */
    private function progressTables(): array
    {
        return [
            'nhl_import_progress',
            'nhl_game_import_runs',
        ];
    }

    /**
     * Return NHL-owned player import tables in dependency-safe delete order.
     *
     * @return array<int,string>
     */
    private function playerTables(): array
    {
        return [
            'stats',
        ];
    }

    /**
     * Clear NHL game-derived tables with per-table progress.
     *
     * @return array<string,int>
     */
    private function emptyGameTables(): array
    {
        $counts = [];

        foreach (array_merge($this->gameTables(), $this->progressTables()) as $table) {
            $this->line("Clearing {$table}...");
            $count = DB::table($table)->count();
            $counts[$table] = $count;

            if ($count > 0) {
                $this->clearTable($table, $count);
            }

            $this->line("{$table}: {$count}");
        }

        return $counts;
    }

    /**
     * Clear NHL player import tables with per-table progress.
     *
     * @return array<string,int>
     */
    private function emptyPlayerTables(): array
    {
        $counts = [];

        foreach ($this->playerTables() as $table) {
            $this->line("Clearing {$table}...");
            $count = DB::table($table)->count();
            $counts[$table] = $count;

            if ($count > 0) {
                $this->clearTable($table, $count);
            }

            $this->line("{$table}: {$count}");
        }

        return $counts;
    }

    /**
     * Clear a table in chunks when a known key is available.
     */
    private function clearTable(string $table, int $total): void
    {
        $key = $this->deleteKeyForTable($table);

        if ($key === null) {
            DB::table($table)->delete();

            return;
        }

        $deleted = 0;

        do {
            $ids = DB::table($table)
                ->orderBy($key)
                ->limit(self::GAME_DELETE_CHUNK_SIZE)
                ->pluck($key)
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table($table)
                ->whereIn($key, $ids)
                ->delete();

            $this->line("{$table}: cleared {$deleted} / {$total}");
        } while (count($ids) === self::GAME_DELETE_CHUNK_SIZE);
    }

    /**
     * Return the key used for chunked full-table cleanup.
     */
    private function deleteKeyForTable(string $table): ?string
    {
        return match ($table) {
            'nhl_games' => 'nhl_game_id',
            default => 'id',
        };
    }

    /**
     * Remove NHL-owned player identity links while preserving players.
     */
    private function emptyPlayerIdentities(): int
    {
        $this->line('Clearing player_external_identities...');

        return DB::transaction(function (): int {
            $query = DB::table('player_external_identities')
                ->whereIn('provider', $this->identityProviders());
            $count = $query->count();

            if ($count > 0) {
                $query->delete();
            }

            $this->line("player_external_identities: {$count}");

            return $count;
        });
    }

    /**
     * Return a message describing the selected cleanup mode.
     */
    private function successMessage(bool $players, bool $games): string
    {
        if ($players && $games) {
            return 'Removed NHL player stats, identities, and game import data.';
        }

        if ($players) {
            return 'Removed NHL player stats and external identities.';
        }

        return 'Removed NHL game import data.';
    }

    /**
     * Return NHL-owned external identity providers.
     *
     * @return array<int,string>
     */
    private function identityProviders(): array
    {
        return [
            PlayerExternalIdentity::PROVIDER_NHL,
            PlayerExternalIdentity::PROVIDER_NHL_DRAFT,
        ];
    }
}
