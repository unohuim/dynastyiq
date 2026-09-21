<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One normalized player slot within a lineup observation. */
class NhlLineupObservationPlayer extends Model
{
    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'team_id' => 'integer',
        'player_id' => 'integer',
        'nhl_player_id' => 'integer',
        'slot_index' => 'integer',
        'power_play_unit' => 'integer',
        'penalty_kill_unit' => 'integer',
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(NhlLineupObservation::class, 'nhl_lineup_observation_id');
    }
}
