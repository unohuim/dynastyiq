<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportNhlAnticipatedLineupTeamJob;
use App\Models\ImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Queues one anticipated-lineup discovery job per eligible team. */
class ImportNhlAnticipatedLineupsCommand extends Command
{
    protected $signature = 'nhl:import-anticipated-lineups
        {--window=all : all, within-two-hours, or outside-two-hours}
        {--import-run-id= : Internal admin import run id}';

    protected $description = 'Queue anticipated-lineup discovery for teams playing today';

    public function handle(): int
    {
        $window = (string) $this->option('window');
        if (! in_array($window, ['all', 'within-two-hours', 'outside-two-hours'], true)) {
            $this->error('Window must be all, within-two-hours, or outside-two-hours.');
            return self::FAILURE;
        }

        $today = Carbon::now('America/Toronto')->toDateString();
        $games = DB::table('nhl_games')->whereDate('game_date', $today)
            ->whereNotNull('start_time_utc')->where('start_time_utc', '>', now())
            ->when($window === 'within-two-hours', fn ($query) => $query->where('start_time_utc', '<=', now()->addHours(2)))
            ->when($window === 'outside-two-hours', fn ($query) => $query->where('start_time_utc', '>', now()->addHours(2)))
            ->orderBy('start_time_utc')->get();

        $jobs = $games->flatMap(function (object $game): array {
            return [
                [$game, mb_strtoupper((string) $game->away_team_abbrev)],
                [$game, mb_strtoupper((string) $game->home_team_abbrev)],
            ];
        })->filter(fn (array $entry): bool => $entry[1] !== '')->values();

        $run = $this->importRun();
        $run?->setProgressTotal($jobs->count(), 'Team lineup searches');
        if ($jobs->isEmpty()) {
            $run?->markCompleted();
            $this->info('No eligible teams remain today.');
            return self::SUCCESS;
        }

        foreach ($jobs as [$game, $teamAbbrev]) {
            $teamId = DB::table('nhl_teams')->where('abbrev', $teamAbbrev)->value('nhl_id');
            if ($teamId === null) {
                $run?->recordProcessed('skipped');
                continue;
            }
            ImportNhlAnticipatedLineupTeamJob::dispatch(
                (int) $game->nhl_game_id,
                $teamAbbrev,
                (int) $teamId,
                $run?->id,
            );
        }

        $run?->refresh();
        if (($run?->processed_records ?? 0) >= ($run?->total_records ?? 1)) {
            $run?->markCompleted();
        }
        $this->info("Queued {$jobs->count()} team lineup searches.");

        return self::SUCCESS;
    }

    private function importRun(): ?ImportRun
    {
        return $this->option('import-run-id')
            ? ImportRun::query()->find((int) $this->option('import-run-id'))
            : null;
    }
}
