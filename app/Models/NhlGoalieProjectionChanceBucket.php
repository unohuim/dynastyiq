<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned goalie projection contribution for one faced chance bucket.
 */
class NhlGoalieProjectionChanceBucket extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'bucket_dimensions' => 'array',
        'projection_strength' => 'string',
        'source_sat_against' => 'integer',
        'source_sog_against' => 'integer',
        'source_goals_against' => 'integer',
        'source_xga' => 'decimal:4',
        'source_xsoga' => 'decimal:4',
        'source_gsax' => 'decimal:4',
        'source_gsax_per_100_sat_against' => 'decimal:4',
        'source_profile_share' => 'decimal:6',
        'projected_sata' => 'decimal:2',
        'projected_soga' => 'decimal:2',
        'projected_xga' => 'decimal:4',
        'projected_ga' => 'decimal:4',
        'projected_gsax' => 'decimal:4',
        'projected_xsoga' => 'decimal:4',
        'projected_profile_share' => 'decimal:6',
        'goal_probability_against' => 'decimal:6',
        'shot_on_goal_probability_against' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Owning goalie season projection.
     *
     * @return BelongsTo<NhlGoalieSeasonProjection, NhlGoalieProjectionChanceBucket>
     */
    public function projection(): BelongsTo
    {
        return $this->belongsTo(NhlGoalieSeasonProjection::class, 'goalie_season_projection_id');
    }
}
