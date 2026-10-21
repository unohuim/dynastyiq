<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Durable, bounded orchestration record for a pregame-context build. */
class NhlPregameContextRun extends Model
{
    public const ACTION_BACKFILL = 'backfill';
    public const ACTION_REBUILD_FORWARD = 'rebuild-forward';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSING = 'pausing';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Return the shared-cache key used to cancel this run's active jobs. */
    public static function stopSignalKey(int $runId): string
    {
        return "nhl-pregame-context-run-{$runId}-stopped";
    }

    protected $guarded = [];

    protected $casts = [
        'season_ids' => 'array',
        'options' => 'array',
        'model_run_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'current_game_date' => 'date',
    ];

    /** Resolve the latest context build initiated from this SAT model. */
    public static function latestForModel(int $modelId): ?self
    {
        return static::query()->where('model_run_id', $modelId)->orderByDesc('id')->first();
    }

    /** Allow inspection only after a model-menu build has saved current context evidence. */
    public function canViewImpacts(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->model_run_id !== null
            && \Illuminate\Support\Facades\DB::table('nhl_player_game_pregame_contexts')
                ->where('run_id', $this->id)
                ->where('model_run_id', $this->model_run_id)
                ->where('context_version', \App\Services\NhlPregameContextBuilder::VERSION)
                ->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function games(): HasMany
    {
        return $this->hasMany(NhlPregameContextRunGame::class, 'run_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(NhlPregameContextRunDay::class, 'run_id');
    }

    /** Read progress for the league-first and integrated context stages. */
    public function stageProgress(): ?array
    {
        if (data_get($this->options, 'pipeline_version') !== \App\Services\NhlPregameContextOrchestrator::RESULTS_PIPELINE) {
            return null;
        }
        $row = $this->days()->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN context_state = 'complete' THEN 1 ELSE 0 END) AS context")
            ->selectRaw("SUM(CASE WHEN league_state = 'complete' THEN 1 ELSE 0 END) AS league")->first();

        return ['total' => (int) ($row->total ?? 0), 'context' => (int) ($row->context ?? 0),
            'league' => (int) ($row->league ?? 0)];
    }
}
