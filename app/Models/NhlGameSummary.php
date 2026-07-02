<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhlGameSummary extends Model
{
    protected $guarded = [];

    protected $casts = [
        'goalie_started' => 'boolean',
        'quality_start' => 'boolean',
        'really_bad_start' => 'boolean',
        'sv_pct' => 'float',
        'gaa' => 'float',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'nhl_player_id', 'nhl_id');
    }

    // public function team(): BelongsTo
    // {
    //     return $this->belongsTo(Team::class, 'nhl_team_id');
    // }
}
