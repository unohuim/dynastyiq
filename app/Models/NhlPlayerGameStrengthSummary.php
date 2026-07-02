<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Strength-specific on-ice totals for an NHL player in one game.
 */
class NhlPlayerGameStrengthSummary extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ipp' => 'float',
    ];

    /**
     * Get the canonical player this strength summary belongs to.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * Get the NHL game this strength summary belongs to.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }
}
