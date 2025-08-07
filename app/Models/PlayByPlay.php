<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class PlayByPlay
 *
 * Represents a single play-by-play event within an NHL game.
 *
 * @package App\Models
 */
class PlayByPlay extends Model
{
    // Allow mass assignment on all attributes
    protected $guarded = [];

    /**
     * Get the game associated with this play-by-play event.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Players involved in the play-by-play events.
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'id', 'player_id');
    }

    /**
     * Player game summaries for the same game as this play-by-play event.
     */
    public function gameSummaries(): HasMany
    {
        return $this->hasMany(NhlGameSummary::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Shifts for the same game as this play-by-play event.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(NhlShift::class, 'nhl_game_id', 'nhl_game_id');
    }
}
