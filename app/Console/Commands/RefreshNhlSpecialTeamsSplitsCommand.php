<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshNhlSpecialTeamsSplitsJob;
use App\Services\SumNHLPlayByPlay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds NHL game-summary special-teams splits without refetching provider data.
 */
class RefreshNhlSpecialTeamsSplitsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:refresh-special-teams-splits
        {--season= : NHL season id like 20252026}
        {--game-id= : Single NHL game id}
        {--date-from= : Inclusive game date lower bound, YYYY-MM-DD}
        {--date-to= : Inclusive game date upper bound, YYYY-MM-DD}
        {--queue : Dispatch one queue job per game instead of refreshing inline}';

    /**
     * @var string
     */
    protected $description = 'Refresh PBP-derived NHL game-summary special-teams splits without rerunning full game imports.';

    /**
     * Execute the console command.
     */
    public function handle(SumNHLPlayByPlay $summary): int
    {
        $gameIds = $this->gameIds();

        if ($gameIds === []) {
            $this->error('No NHL games matched the provided options.');

            return self::FAILURE;
        }

        if ((bool) $this->option('queue')) {
            foreach ($gameIds as $gameId) {
                RefreshNhlSpecialTeamsSplitsJob::dispatch($gameId);
            }

            $this->info('Dispatched ' . count($gameIds) . ' special-teams split refresh job(s).');

            return self::SUCCESS;
        }

        $processedGames = 0;
        $updatedRows = 0;

        foreach ($gameIds as $gameId) {
            $updatedRows += $summary->summarize($gameId, reconcileGoalies: false);
            $processedGames++;

            if ($processedGames % 25 === 0 || $processedGames === count($gameIds)) {
                $this->line("Processed {$processedGames} / " . count($gameIds) . ' games.');
            }
        }

        $this->info("Refreshed special-teams splits for {$processedGames} games; updated {$updatedRows} summary rows.");

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
