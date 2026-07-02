<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhlSeasonStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quality_start_percentage' => 'float',
        'sv_pct' => 'float',
        'gaa' => 'float',
        'ev_sv_pct' => 'float',
        'pp_sv_pct' => 'float',
        'pk_sv_pct' => 'float',
    ];

    /**
     * Player relation (nhl_player_id → players.nhl_id).
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'nhl_player_id', 'nhl_id');
    }
}
