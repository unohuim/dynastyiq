<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Strength-specific on-ice totals for an NHL unit in one game.
 */
class NhlUnitGameStrengthSummary extends Model
{
    protected $guarded = [];

    /**
     * Get the unit this strength summary belongs to.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(NhlUnit::class, 'unit_id');
    }

    /**
     * Get the NHL game this strength summary belongs to.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }
}
