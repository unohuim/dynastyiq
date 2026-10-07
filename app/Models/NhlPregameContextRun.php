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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function games(): HasMany
    {
        return $this->hasMany(NhlPregameContextRunGame::class, 'run_id');
    }
}
