<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildNhlPregameContextGameJob;
use App\Jobs\LoadNhlPregameContextRunJob;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Coordinates durable, date-ordered pregame-context work without queue flooding. */
class NhlPregameContextOrchestrator
{
    private const DISPATCH_PAGE_SIZE = 12;

    /** Materialize durable game work rows for a queued context run. */
    public function prepare(NhlPregameContextRun $run): void
    {
        if ($run->status !== NhlPregameContextRun::STATUS_QUEUED) {
            return;
        }

        $games = DB::table('nhl_games')
            ->where('game_type', 2)
            ->whereIn('game_state', ['OFF', 'FINAL'])
            ->whereIn('season_id', $run->season_ids)
            ->when($run->start_date !== null, fn ($query) => $query->whereDate('game_date', '>=', $run->start_date))
            ->when($run->end_date !== null, fn ($query) => $query->whereDate('game_date', '<=', $run->end_date))
            ->orderBy('game_date')
            ->orderBy('start_time_utc')
            ->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'game_date']);

        DB::transaction(function () use ($run, $games): void {
            $fresh = NhlPregameContextRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($fresh->status !== NhlPregameContextRun::STATUS_QUEUED) {
                return;
            }

            $now = now();
            $rows = $games->values()->map(fn (object $game, int $index): array => [
                'run_id' => $fresh->id,
                'nhl_game_id' => $game->nhl_game_id,
                'game_date' => $game->game_date,
                'sequence' => $index + 1,
                'state' => NhlPregameContextRunGame::STATE_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('nhl_pregame_context_run_games')->upsert(
                    $chunk,
                    ['run_id', 'nhl_game_id'],
                    ['game_date', 'sequence', 'updated_at']
                );
            }

            $fresh->update([
                'status' => NhlPregameContextRun::STATUS_RUNNING,
                'total_games' => count($rows),
                'last_error' => null,
            ]);
        });

        LoadNhlPregameContextRunJob::dispatch($run->id);
    }

    /** Claim and dispatch one bounded page from the earliest unfinished date. */
    public function load(int $runId): void
    {
        $lock = Cache::lock("nhl-pregame-context-run-loader:{$runId}", 30);
        if (! $lock->get()) {
            return;
        }

        try {
            $gameIds = DB::transaction(function () use ($runId): array {
                $run = NhlPregameContextRun::query()->lockForUpdate()->find($runId);
                if ($run === null || $run->status !== NhlPregameContextRun::STATUS_RUNNING) {
                    return [];
                }

                $date = NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereIn('state', [NhlPregameContextRunGame::STATE_PENDING, NhlPregameContextRunGame::STATE_READY, NhlPregameContextRunGame::STATE_PROCESSING])
                    ->orderBy('game_date')
                    ->value('game_date');

                if ($date === null) {
                    $this->refreshRunCounts($run);
                    $run->update(['status' => NhlPregameContextRun::STATUS_COMPLETED, 'current_game_date' => null]);

                    return [];
                }

                $run->update(['current_game_date' => $date]);
                NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereDate('game_date', $date)
                    ->where('state', NhlPregameContextRunGame::STATE_PENDING)
                    ->update(['state' => NhlPregameContextRunGame::STATE_READY, 'updated_at' => now()]);

                $work = NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereDate('game_date', $date)
                    ->where('state', NhlPregameContextRunGame::STATE_READY)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->limit(self::DISPATCH_PAGE_SIZE)
                    ->get();

                if ($work->isEmpty()) {
                    $this->refreshRunCounts($run);

                    return [];
                }

                $ids = $work->pluck('nhl_game_id')->map(fn (mixed $id): int => (int) $id)->all();
                NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereIn('nhl_game_id', $ids)
                    ->update([
                        'state' => NhlPregameContextRunGame::STATE_PROCESSING,
                        'attempts' => DB::raw('attempts + 1'),
                        'started_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->refreshRunCounts($run);

                return $ids;
            });

            foreach ($gameIds as $gameId) {
                BuildNhlPregameContextGameJob::dispatch($runId, $gameId);
            }
        } finally {
            $lock->release();
        }
    }

    /** Update cached counters from durable work rows. */
    public function refreshRunCounts(NhlPregameContextRun $run): void
    {
        $counts = NhlPregameContextRunGame::query()->where('run_id', $run->id)
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as ready_games', [NhlPregameContextRunGame::STATE_READY])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as processing_games', [NhlPregameContextRunGame::STATE_PROCESSING])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as completed_games', [NhlPregameContextRunGame::STATE_COMPLETE])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as blocked_games', [NhlPregameContextRunGame::STATE_BLOCKED])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as failed_games', [NhlPregameContextRunGame::STATE_FAILED])
            ->first();

        $run->update([
            'ready_games' => (int) ($counts->ready_games ?? 0),
            'processing_games' => (int) ($counts->processing_games ?? 0),
            'completed_games' => (int) ($counts->completed_games ?? 0),
            'blocked_games' => (int) ($counts->blocked_games ?? 0),
            'failed_games' => (int) ($counts->failed_games ?? 0),
        ]);
    }
}
