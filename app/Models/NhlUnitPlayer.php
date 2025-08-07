<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhlUnitPlayer extends Model
{
    protected $fillable = [
        'unit_id',
        'player_id',
        'team_id',
        'team_abbrev',
    ];

    /**
     * Get the unit that owns this pivot.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(NhlUnit::class, 'unit_id');
    }

    /**
     * Get the player for this pivot.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
