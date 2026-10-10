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
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'season_ids' => 'array',
        'options' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'current_game_date' => 'date',
    ];

    /** Resolve the latest context build initiated from this SAT model. */
    public static function latestForModel(int $modelId): ?self
    {
        return static::query()->where('options->model_run_id', $modelId)->orderByDesc('id')->first();
    }

    /** Allow inspection only after a model-menu build has saved current context evidence. */
    public function canViewImpacts(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && isset($this->options['model_run_id'])
            && \Illuminate\Support\Facades\DB::table('nhl_player_game_pregame_contexts')
                ->where('run_id', $this->id)
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
}
