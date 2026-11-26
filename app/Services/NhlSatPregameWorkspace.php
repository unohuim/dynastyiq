<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Read model-owned evidence through one shared-workspace pilot without changing prediction ownership. */
class NhlSatPregameWorkspace
{
    /** Match the explicitly approved historical pilot, never the newest or default model. */
    public static function isPilot(NhlModelRun $model): bool
    {
        $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) $model->name));
        $expected = preg_replace('/[^a-z0-9]/', '', strtolower((string) config('nhl-pregame.workspace_model_name', 'Sep2026')));

        return $name === $expected && $model->model_family === 'sat'
            && $model->workflow_stage === 'training' && (string) $model->target_season_id === '20252026';
    }

    /** Ambiguous or absent pilot configuration disables pilot actions rather than choosing production. */
    public function pilot(): ?NhlModelRun
    {
        $models = NhlModelRun::query()->where('model_family', 'sat')->where('workflow_stage', 'training')
            ->where('target_season_id', '20252026')->get()->filter(fn ($model) => self::isPilot($model));

        return $models->count() === 1 ? $models->first() : null;
    }

    /** Reuse configured stage limits only for the approved pilot. */
    public static function limits(NhlModelRun $model): array
    {
        $limits = ['league' => max(1, (int) config('nhl-pregame.league_concurrency', 4)),
            'context' => max(1, (int) config('nhl-pregame.context_concurrency', 2))];
        if (! self::isPilot($model)) {
            return $limits;
        }
        foreach ($limits as $stage => $default) {
            $limits[$stage] = max(1, min(25, (int) data_get($model->run_config, 'pregame_workspace.'.$stage, $default)));
        }

        return $limits;
    }

    /** Explicit, small run progress contract shared by Vue actions and polls. */
    public function progress(?NhlPregameContextRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $processed = (int) $run->completed_games + (int) $run->blocked_games + (int) $run->failed_games;
        $total = (int) $run->total_games;
        $percent = $total > 0 ? round(min(100, 100 * $processed / $total), 1)
            : ($run->status === 'completed' ? 100.0 : null);

        return ['id' => (int) $run->id, 'status' => $run->status, 'completed' => (int) $run->completed_games,
            'processed' => $processed, 'progress_pct' => $percent, 'created_at' => $run->created_at?->toIso8601String(),
            'total' => (int) $run->total_games, 'excluded' => (int) $run->blocked_games,
            'failed' => (int) $run->failed_games, 'error' => $run->last_error,
            'updated_at' => $run->updated_at?->toIso8601String(), 'stages' => $run->stageProgress(),
            'up_to_date' => (bool) data_get($run->options, 'up_to_date', false),
            'retryable' => $run->status === 'failed' && in_array(data_get($run->options, 'pipeline_version'),
                [NhlPregameContextOrchestrator::RESULTS_PIPELINE, NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE], true)];
    }

    /** Read a bounded operational view; expensive historical counts refresh at most once a minute. */
    public function payload(): array
    {
        $pilot = $this->pilot();
        if ($pilot === null) {
            return ['source' => null, 'worker_activity' => ['available' => false, 'workers' => []], 'health' => 'unavailable', 'issues' => [
                ['title' => 'Sep2026 source unavailable', 'detail' => 'Exactly one Sep2026 SAT training model with Test 2025–26 is required. Production is never selected automatically.'],
            ], 'workstreams' => [], 'workers' => [], 'coverage' => [], 'history' => [], 'run' => null];
        }
        $run = NhlPregameContextRun::latestForModel($pilot->id);
        $facts = Cache::remember('sat-pregame-workspace:'.$pilot->id.':'.NhlPregameContextBuilder::VERSION, 60,
            fn (): array => $this->coverage($pilot));
        $days = $run === null ? collect() : NhlPregameContextRunDay::query()->where('run_id', $run->id)
            ->where(fn ($q) => $q->whereIn('league_state', ['processing', 'failed'])
                ->orWhereIn('context_state', ['processing', 'failed'])->orWhereNotNull('last_error'))
            ->orderBy('game_date')->limit(100)->get();
        $workers = [];
        foreach ($days as $day) {
            foreach (['league', 'context'] as $stage) {
                if ($day->{$stage.'_state'} !== 'processing') {
                    continue;
                }
                $expires = $day->{$stage.'_claim_expires_at'};
                $workers[] = ['key' => $day->id.'-'.$stage, 'stage' => $stage, 'date' => $day->game_date->format('Y-m-d'),
                    'state' => $expires !== null && $expires->isPast() ? 'expired' : 'claimed',
                    'started_at' => $day->{$stage.'_started_at'}?->toIso8601String(),
                    'last_activity' => $day->updated_at?->toIso8601String(),
                    'expires_at' => $expires?->toIso8601String()];
            }
        }
        $issues = [];
        if ($run?->last_error || $run?->status === 'failed') {
            $issues[] = ['title' => 'Latest maintenance needs attention', 'detail' => $run->last_error ?: 'The latest maintenance run failed. Inspect its history before retrying.'];
        }
        if ((int) $run?->blocked_games > 0) {
            $issues[] = ['title' => $run->blocked_games.' excluded games', 'detail' => 'Source readiness excluded these games from the latest maintenance run. Inspect coverage before rebuilding.'];
        }
        if ((int) $run?->failed_games > 0) {
            $issues[] = ['title' => $run->failed_games.' failed games', 'detail' => 'Retry unfinished work after reviewing the source error.'];
        }
        if (collect($workers)->contains('state', 'expired')) {
            $issues[] = ['title' => 'Expired work claims', 'detail' => 'A delivery has not renewed its claim. Existing scheduled recovery can reclaim it; no worker process is stopped here.'];
        }
        foreach ($facts['seasons'] as $season) {
            if ($season['covered'] < $season['games']) {
                $issues[] = ['title' => $season['season'].' coverage gaps',
                    'detail' => ($season['games'] - $season['covered']).' completed games lack both team snapshots. Player and metric completeness require separate inspection.'];
            }
        }
        $health = $issues !== [] ? 'attention' : ($facts['teams'] === 0 ? 'incomplete' : 'recorded');
        $progress = $this->progress($run);
        $streams = [];
        foreach ([['players', 'Player context', 'context', 'Player form, usage and opponent history'],
            ['teams', 'Team context', 'context', 'Team performance and opponent context'],
            ['goalies', 'Goalie context', 'context', 'Goalie history within player snapshots'],
            ['league', 'League averages', 'league', 'Results-based league reference populations'],
            ['rankings', 'Rankings & deltas', 'context', 'Comparisons attached during context processing'],
            ['travel', 'Schedule & travel', 'context', 'Schedule evidence maintained with team snapshots']] as [$key, $name, $stage, $description]) {
            $activeClaims = collect($workers)->where('stage', $stage)->count();
            $activity = $run?->status ?? 'no_run';
            if ((int) data_get($progress, 'stages.total') > 0
                && data_get($progress, 'stages.'.$stage) === data_get($progress, 'stages.total')) {
                $activity = 'completed';
            } elseif ($activeClaims > 0 && $activity === 'running') {
                $activity = 'running';
            } elseif ($stage === 'context' && $activity === 'running' && $activeClaims === 0
                && data_get($progress, 'stages.league') < data_get($progress, 'stages.total')) {
                $activity = 'waiting';
            }
            $streams[] = ['key' => $key, 'name' => $name, 'stage' => $stage, 'description' => $description,
                'state' => $activity, 'active_claims' => $activeClaims,
                'completed_days' => data_get($progress, 'stages.'.$stage), 'total_days' => data_get($progress, 'stages.total'),
                'snapshot_count' => match ($key) { 'players' => $facts['players'], 'teams', 'travel' => $facts['teams'], default => null },
                'note' => $key === 'goalies' ? 'No separate goalie processing pool or verified goalie coverage count is recorded.'
                    : ($stage === 'context' ? 'Shares context claims with the other context workstreams; do not add these counts together.' : 'League references are prepared before each context date.')];
        }
        $history = NhlPregameContextRun::query()->where('model_run_id', $pilot->id)->orderByDesc('id')->limit(10)->get()
            ->map(fn ($item) => $this->progress($item));

        $schedule = \App\Models\ScheduledProcess::query()->where('key', ScheduledProcessManager::pregameContextKey($pilot->id))->first();

        return ['automation' => ['enabled' => (bool) $schedule?->enabled,
                'settings_url' => route('admin.nhl-sat-models.context.auto-sync.show', $pilot),
                'toggle_url' => route('admin.nhl-sat-models.context.auto-sync.toggle', $pilot)],
            'source' => ['id' => (int) $pilot->id, 'name' => $pilot->name, 'train' => $pilot->train_season_ids,
                'test' => $pilot->target_season_id], 'health' => $health, 'issues' => $issues,
            'workstreams' => $streams, 'workers' => $workers,
            'worker_activity' => app(NhlPregameWorkerActivity::class)->snapshot($pilot), 'coverage' => $facts['seasons'], 'dates' => $facts['dates'] ?? [],
            'snapshots' => ['players' => $facts['players'], 'teams' => $facts['teams']],
            'source_cutoff_at' => $facts['cutoff'], 'counts_checked_at' => $facts['checked_at'],
            'last_activity' => $run?->updated_at?->toIso8601String(), 'run' => $progress,
            'limits' => self::limits($pilot), 'history' => $history->all(),
            'inspector_url' => route('admin.nhl-sat-models.workspace.inspect'),
            'workers_url' => route('admin.nhl-sat-models.workspace.workers')];
    }

    /** Historical coverage counts are observations, never inferred full metric readiness. */
    private function coverage(NhlModelRun $pilot): array
    {
        $seasons = [];
        $contexts = DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $pilot->id)
            ->where('context_version', NhlPregameContextBuilder::VERSION);
        $covered = (clone $contexts)->select('nhl_game_id')->groupBy('nhl_game_id')
            ->havingRaw('COUNT(DISTINCT nhl_team_id) >= 2');
        foreach ($pilot->pregameContextSeasonIds() as $season) {
            $games = DB::table('nhl_games')->where('season_id', $season)->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL']);
            $seasons[] = ['season' => $season, 'games' => (clone $games)->count(),
                'covered' => (clone $games)->whereIn('nhl_game_id', clone $covered)->count()];
        }

        $testGames = DB::table('nhl_games')->where('season_id', $pilot->target_season_id)
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL']);
        $coveredDates = (clone $testGames)->whereIn('nhl_game_id', clone $covered)->select('game_date')
            ->selectRaw('COUNT(*) AS covered')->groupBy('game_date')->get()->keyBy('game_date');
        $dates = (clone $testGames)->select('game_date')->selectRaw('COUNT(*) AS games')->groupBy('game_date')
            ->orderBy('game_date')->get()->map(fn ($date): array => ['date' => $date->game_date,
                'games' => (int) $date->games, 'covered' => (int) ($coveredDates->get($date->game_date)?->covered ?? 0)])->all();

        return ['dates' => $dates, 'seasons' => $seasons, 'teams' => (clone $contexts)->count(),
            'players' => DB::table('nhl_player_game_pregame_contexts')->where('model_run_id', $pilot->id)
                ->where('context_version', NhlPregameContextBuilder::VERSION)->count(),
            'cutoff' => ($cutoff = (clone $contexts)->max('source_cutoff_at')) === null ? null : \Carbon\CarbonImmutable::parse($cutoff, 'UTC')->toIso8601String(), 'checked_at' => now()->toIso8601String()];
    }

    /** Inspect one existing game and one entity population with bounded, explicit metric rows. */
    public function inspect(int $gameId, string $entity): array
    {
        $pilot = $this->pilot();
        abort_if($pilot === null, 409, 'Sep2026 pilot source is unavailable.');
        $player = $entity === 'player';
        $table = $player ? 'nhl_player_game_pregame_contexts' : 'nhl_team_game_pregame_contexts';
        $foreign = $player ? 'player_context_id' : 'team_context_id';
        $contexts = DB::table($table)->where('model_run_id', $pilot->id)->where('nhl_game_id', $gameId)
            ->where('context_version', NhlPregameContextBuilder::VERSION)->orderBy('id')->limit(50)
            ->get(['id', 'nhl_team_id', ...($player ? ['nhl_player_id'] : []), 'source_cutoff_at']);
        $metrics = DB::table($player ? 'nhl_player_game_pregame_context_metrics' : 'nhl_team_game_pregame_context_metrics')
            ->whereIn($foreign, $contexts->pluck('id'))->orderBy($foreign)->orderBy('metric_key')
            ->orderBy('window_key')->orderBy('strength')->orderBy('measure_key')->limit(201)->get();

        return ['source_model_id' => (int) $pilot->id, 'contexts' => $contexts, 'metrics' => $metrics->take(200)->values(),
            'truncated' => $metrics->count() > 200, 'entity' => $entity, 'game_id' => $gameId];
    }
}
