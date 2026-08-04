<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned player-season time-on-ice projection row.
 */
class NhlPlayerToiProjection extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'age_years' => 'decimal:2',
        'source_games' => 'decimal:2',
        'source_toi_per_game_seconds' => 'decimal:2',
        'projected_games' => 'decimal:2',
        'projected_toi_per_game_seconds' => 'decimal:2',
        'projected_toi_hours' => 'decimal:4',
        'age_adjustment_seconds_per_game' => 'decimal:2',
        'role_adjustment_seconds_per_game' => 'decimal:2',
        'team_change_adjustment_seconds_per_game' => 'decimal:2',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'projected_at' => 'datetime',
    ];
}
