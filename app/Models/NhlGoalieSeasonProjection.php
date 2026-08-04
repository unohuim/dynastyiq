<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned goalie season projection rollup.
 */
class NhlGoalieSeasonProjection extends Model
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
        'projected_ev_sata' => 'decimal:2',
        'projected_ev_soga' => 'decimal:2',
        'projected_ev_xga' => 'decimal:4',
        'projected_ev_ga' => 'decimal:4',
        'projected_ev_gsax' => 'decimal:4',
        'projected_ev_xsoga' => 'decimal:4',
        'projected_pk_sata' => 'decimal:2',
        'projected_pk_soga' => 'decimal:2',
        'projected_pk_xga' => 'decimal:4',
        'projected_pk_ga' => 'decimal:4',
        'projected_pk_gsax' => 'decimal:4',
        'projected_pk_xsoga' => 'decimal:4',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'projected_at' => 'datetime',
    ];

    /**
     * Bucket rows that explain this goalie projection.
     *
     * @return HasMany<NhlGoalieProjectionChanceBucket>
     */
    public function buckets(): HasMany
    {
        return $this->hasMany(NhlGoalieProjectionChanceBucket::class, 'goalie_season_projection_id');
    }
}
