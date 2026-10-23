<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Durable day-level progress for batched pregame snapshots and benchmarks. */
class NhlPregameContextRunDay extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_PROCESSING = 'processing';
    public const STATE_COMPLETE = 'complete';
    public const STATE_FAILED = 'failed';
    public const STATE_STOPPED = 'stopped';

    protected $guarded = [];

    protected $casts = [
        'game_date' => 'date',
        'league_cursor' => 'integer',
        'league_entity_cursor' => 'integer',
        'league_claim_expires_at' => 'datetime',
        'context_claim_expires_at' => 'datetime',
        'league_seconds' => 'float',
        'context_seconds' => 'float',
        'comparison_seconds' => 'float',
        'context_started_at' => 'datetime',
        'context_completed_at' => 'datetime',
        'league_started_at' => 'datetime',
        'league_completed_at' => 'datetime',
        'comparison_started_at' => 'datetime',
        'comparison_completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(NhlPregameContextRun::class, 'run_id');
    }

    /** Resolve the league-average groups calculated for this game date. */
    public function leagueAverages(): HasMany
    {
        return $this->hasMany(NhlPregameContextLeagueAverage::class, 'run_day_id');
    }
}
