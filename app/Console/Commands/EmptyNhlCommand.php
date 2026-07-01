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
    /**
     * @var string
     */
    protected $signature = 'nhl:empty
        {--players : Remove NHL player external identities}
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

        $counts = DB::transaction(function () use ($emptyPlayers, $emptyGames): array {
            $counts = [];

            if ($emptyGames) {
                foreach ($this->gameTables() as $table) {
                    $counts[$table] = DB::table($table)->count();
                }
            }

            if ($emptyPlayers) {
                $counts['player_external_identities'] = DB::table('player_external_identities')
                    ->whereIn('provider', $this->identityProviders())
                    ->count();
            }

            if ($emptyGames) {
                foreach ($this->gameTables() as $table) {
                    DB::table($table)->delete();
                }
            }

            if ($emptyPlayers) {
                DB::table('player_external_identities')
                    ->whereIn('provider', $this->identityProviders())
                    ->delete();
            }

            return $counts;
        });

        $this->info($this->successMessage($emptyPlayers, $emptyGames));

        foreach ($counts as $table => $count) {
            $this->line("{$table}: {$count}");
        }

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
            'nhl_import_progress',
            'nhl_game_import_runs',
            'nhl_game_source_statuses',
            'nhl_games',
        ];
    }

    /**
     * Return a message describing the selected cleanup mode.
     */
    private function successMessage(bool $players, bool $games): string
    {
        if ($players && $games) {
            return 'Removed NHL player identities and game import data.';
        }

        if ($players) {
            return 'Removed NHL player external identities.';
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
