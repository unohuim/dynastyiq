<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhlSeasonStat extends Model
{
    protected $guarded = [];

    /**
     * Player relation (nhl_player_id → players.nhl_id).
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'nhl_player_id', 'nhl_id');
    }
}
