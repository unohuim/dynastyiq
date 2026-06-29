<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes NHL-owned imported data while preserving canonical player records.
 */
class EmptyNhlCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:empty';

    /**
     * @var string
     */
    protected $description = 'Remove NHL-owned imported data without deleting canonical players or team reference data.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $counts = DB::transaction(function (): array {
            $counts = [];

            foreach ($this->tables() as $table) {
                $counts[$table] = DB::table($table)->count();
            }

            $counts['player_external_identities'] = DB::table('player_external_identities')
                ->whereIn('provider', $this->identityProviders())
                ->count();

            foreach ($this->tables() as $table) {
                DB::table($table)->delete();
            }

            DB::table('player_external_identities')
                ->whereIn('provider', $this->identityProviders())
                ->delete();

            return $counts;
        });

        $this->info('Removed NHL imported data.');

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
    private function tables(): array
    {
        return [
            'event_unit_shifts',
            'nhl_unit_game_summaries',
            'nhl_unit_players',
            'nhl_unit_shifts',
            'nhl_shifts',
            'nhl_units',
            'nhl_boxscores',
            'nhl_game_summaries',
            'play_by_plays',
            'nhl_season_stats',
            'nhl_import_progress',
            'nhl_games',
        ];
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
