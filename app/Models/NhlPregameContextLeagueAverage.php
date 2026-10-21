<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shared league baseline for one model, target cutoff, entity and metric group. */
class NhlPregameContextLeagueAverage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'model_run_id' => 'integer',
        'game_date' => 'date',
        'source_cutoff_at' => 'datetime',
        'league_average' => 'float',
        'rank_population' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(NhlPregameContextRun::class, 'run_id');
    }

    /** Resolve the day-level calculation status row for this benchmark. */
    public function runDay(): BelongsTo
    {
        return $this->belongsTo(NhlPregameContextRunDay::class, 'run_day_id');
    }
}
