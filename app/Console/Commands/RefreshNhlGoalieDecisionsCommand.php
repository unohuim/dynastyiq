<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ImportNhlBoxscore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes NHL goalie decisions from boxscore goalie rows without running the full game pipeline.
 */
class RefreshNhlGoalieDecisionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:refresh-goalie-decisions
        {--season= : NHL season id like 20252026}
        {--game-id= : Single NHL game id}
        {--date-from= : Inclusive game date lower bound, YYYY-MM-DD}
        {--date-to= : Inclusive game date upper bound, YYYY-MM-DD}';

    /**
     * @var string
     */
    protected $description = 'Refresh NHL goalie decisions from boxscores without reprocessing PBP, shifts, or units.';

    /**
     * Execute the console command.
     */
    public function handle(ImportNhlBoxscore $boxscore): int
    {
        $gameIds = $this->gameIds();

        if ($gameIds === []) {
            $this->error('No NHL games matched the provided options.');

            return self::FAILURE;
        }

        $updatedRows = 0;
        $processedGames = 0;

        foreach ($gameIds as $gameId) {
            $updatedRows += $boxscore->refreshGoalieDecisions($gameId);
            $processedGames++;

            if ($processedGames % 25 === 0 || $processedGames === count($gameIds)) {
                $this->line("Processed {$processedGames} / " . count($gameIds) . ' games.');
            }
        }

        $this->info("Refreshed goalie decisions for {$processedGames} games; updated {$updatedRows} summary rows.");

        return self::SUCCESS;
    }

    /**
     * @return array<int,int>
     */
    private function gameIds(): array
    {
        $gameId = trim((string) ($this->option('game-id') ?? ''));

        if ($gameId !== '') {
            return [(int) $gameId];
        }

        $season = trim((string) ($this->option('season') ?? ''));
        $dateFrom = trim((string) ($this->option('date-from') ?? ''));
        $dateTo = trim((string) ($this->option('date-to') ?? ''));

        if ($season === '' && $dateFrom === '' && $dateTo === '') {
            $this->error('Provide --game-id, --season, or a --date-from/--date-to window.');

            return [];
        }

        $query = DB::table('nhl_games')->orderBy('game_date')->orderBy('nhl_game_id');

        if ($season !== '') {
            $query->where('season_id', $season);
        }

        if ($dateFrom !== '') {
            $query->whereDate('game_date', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('game_date', '<=', $dateTo);
        }

        return $query
            ->pluck('nhl_game_id')
            ->map(static fn (mixed $gameId): int => (int) $gameId)
            ->all();
    }
}
