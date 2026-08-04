<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned goalie starts, games, and TOI workload projection.
 */
class NhlGoalieWorkloadProjection extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'source_games' => 'decimal:2',
        'source_starts' => 'decimal:2',
        'source_relief_games' => 'decimal:2',
        'source_toi_seconds' => 'integer',
        'source_sat_against' => 'integer',
        'source_sog_against' => 'integer',
        'source_goals_against' => 'integer',
        'source_xga' => 'decimal:4',
        'source_xsoga' => 'decimal:4',
        'source_gsax' => 'decimal:4',
        'projected_games' => 'decimal:2',
        'projected_starts' => 'decimal:2',
        'projected_relief_games' => 'decimal:2',
        'projected_toi_seconds' => 'integer',
        'projected_toi_hours' => 'decimal:4',
        'projected_sata' => 'decimal:2',
        'projected_soga' => 'decimal:2',
        'projected_xga' => 'decimal:4',
        'projected_ga' => 'decimal:4',
        'projected_gsax' => 'decimal:4',
        'projected_xsoga' => 'decimal:4',
        'age_adjustment_starts' => 'decimal:2',
        'role_adjustment_starts' => 'decimal:2',
        'contract_adjustment_starts' => 'decimal:2',
        'durability_adjustment_starts' => 'decimal:2',
        'contract_cap_hit' => 'integer',
        'contract_aav' => 'integer',
        'contract_years_remaining' => 'integer',
        'team_contract_rank' => 'integer',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'projected_at' => 'datetime',
    ];
}
