<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildNhlPregameContextGameJob;
use App\Jobs\LoadNhlPregameContextRunJob;
use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordinates durable, date-ordered pregame-context work without queue flooding. */
class NhlPregameContextOrchestrator
{
    private const DISPATCH_PAGE_SIZE = 12;

    /** Serialize a new build and preserve its run history. */
    public function queueBackfill(
        array $seasons,
        ?int $modelId = null,
        ?int $createdBy = null,
        array $runOptions = [],
        bool $rejectActive = true,
    ): ?NhlPregameContextRun {
        $lock = Cache::lock('nhl-pregame-context-admission', 30);
        if (! $lock->get()) {
            if ($rejectActive) {
                throw ValidationException::withMessages(['season_ids' => 'Another context build is being queued. Please retry.']);
            }

            return null;
        }

        try {
            return DB::transaction(function () use ($seasons, $modelId, $createdBy, $runOptions, $rejectActive): ?NhlPregameContextRun {
                $active = NhlPregameContextRun::query()
                    ->whereIn('status', [NhlPregameContextRun::STATUS_QUEUED, NhlPregameContextRun::STATUS_RUNNING])
                    ->where(function ($query) use ($seasons): void {
                        foreach ($seasons as $season) {
                            $query->orWhereJsonContains('season_ids', $season);
                        }
                    })->exists();
                if ($active) {
                    if ($rejectActive) {
                        throw ValidationException::withMessages(['season_ids' => 'A context build is already active for one of these seasons.']);
                    }

                    return null;
                }

                $build = NhlPregameContextRun::query()->create([
                    'action' => NhlPregameContextRun::ACTION_BACKFILL,
                    'status' => NhlPregameContextRun::STATUS_QUEUED,
                    'season_ids' => $seasons,
                    'created_by' => $createdBy,
                    'options' => array_merge([
                        'context_version' => NhlPregameContextBuilder::VERSION,
                    ], $modelId === null ? [] : ['model_run_id' => $modelId], $runOptions),
                ]);
                PrepareNhlPregameContextRunJob::dispatch($build->id);

                return $build;
            });
        } finally {
            $lock->release();
        }
    }

    /** Queue the same Train-and-Test context build used by the model's manual action. */
    public function queueModelBackfill(
        NhlModelRun $model,
        ?int $createdBy = null,
        array $runOptions = [],
        bool $rejectActive = true,
    ): ?NhlPregameContextRun {
        $seasons = $model->pregameContextSeasonIds();
        if ($seasons === []) {
            if ($rejectActive) {
                throw ValidationException::withMessages(['season_ids' => 'This model has no seasons to build.']);
            }

            return null;
        }

        return $this->queueBackfill($seasons, $model->id, $createdBy, $runOptions, $rejectActive);
    }

    /** Materialize durable game work rows for a queued context run. */
    public function prepare(NhlPregameContextRun $run): void
    {
        if ($run->status !== NhlPregameContextRun::STATUS_QUEUED) {
            return;
        }

        $query = DB::table('nhl_games')
            ->where('game_type', 2)
            ->whereIn('game_state', ['OFF', 'FINAL'])
            ->whereIn('season_id', $run->season_ids)
            ->when($run->start_date !== null, fn ($query) => $query->whereDate('game_date', '>=', $run->start_date))
            ->when($run->end_date !== null, fn ($query) => $query->whereDate('game_date', '<=', $run->end_date));

        if ((bool) data_get($run->options, 'only_missing', false)) {
            $contextVersion = (string) data_get($run->options, 'context_version', NhlPregameContextBuilder::VERSION);
            $query->where(function ($missing) use ($contextVersion): void {
                $missing->where(function ($teamMissing) use ($contextVersion): void {
                    foreach (['home', 'away'] as $index => $side) {
                        $method = $index === 0 ? 'whereNotExists' : 'orWhereNotExists';
                        $teamMissing->{$method}(function ($teamContext) use ($contextVersion, $side): void {
                            $teamContext->selectRaw('1')
                                ->from('nhl_team_game_pregame_contexts as team_context')
                                ->whereColumn('team_context.nhl_game_id', 'nhl_games.nhl_game_id')
                                ->whereColumn('team_context.nhl_team_id', 'nhl_games.' . $side . '_team_id')
                                ->where('team_context.context_version', $contextVersion);
                        });
                    }
                });
                $missing->orWhereNotExists(function ($summary): void {
                    $summary->selectRaw('1')
                        ->from('nhl_game_summaries as game_summary')
                        ->whereColumn('game_summary.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where(function ($teams): void {
                            $teams->whereColumn('game_summary.nhl_team_id', 'nhl_games.home_team_id')
                                ->orWhereColumn('game_summary.nhl_team_id', 'nhl_games.away_team_id');
                        });
                });
                $missing->orWhereExists(function ($summary) use ($contextVersion): void {
                    $summary->selectRaw('1')
                        ->from('nhl_game_summaries as game_summary')
                        ->whereColumn('game_summary.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where(function ($teams): void {
                            $teams->whereColumn('game_summary.nhl_team_id', 'nhl_games.home_team_id')
                                ->orWhereColumn('game_summary.nhl_team_id', 'nhl_games.away_team_id');
                        })
                        ->whereNotExists(function ($playerContext) use ($contextVersion): void {
                            $playerContext->selectRaw('1')
                                ->from('nhl_player_game_pregame_contexts as player_context')
                                ->whereColumn('player_context.nhl_game_id', 'game_summary.nhl_game_id')
                                ->whereColumn('player_context.nhl_player_id', 'game_summary.nhl_player_id')
                                ->whereColumn('player_context.nhl_team_id', 'game_summary.nhl_team_id')
                                ->where('player_context.context_version', $contextVersion);
                        });
                });
            });
        }

        $games = $query->orderBy('game_date')
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
