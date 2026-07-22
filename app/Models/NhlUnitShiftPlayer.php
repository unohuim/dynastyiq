<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhlUnitShiftPlayer extends Model
{
    protected $fillable = [
        'unit_shift_id',
        'player_id',
        'position_code',
    ];

    /**
     * Get the unit shift this player-position row belongs to.
     */
    public function unitShift(): BelongsTo
    {
        return $this->belongsTo(NhlUnitShift::class, 'unit_shift_id');
    }

    /**
     * Get the player for this shift-position row.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
