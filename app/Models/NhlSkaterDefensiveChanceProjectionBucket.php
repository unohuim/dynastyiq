<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned skater defensive chance projection contribution for one bucket.
 */
class NhlSkaterDefensiveChanceProjectionBucket extends Model
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
        'source_sat_against_on_ice' => 'integer',
        'source_sog_against_on_ice' => 'integer',
        'source_goals_against_on_ice' => 'integer',
        'source_xga_on_ice' => 'decimal:4',
        'source_xsoga_on_ice' => 'decimal:4',
        'source_profile_share_against' => 'decimal:6',
        'projected_sata' => 'decimal:2',
        'projected_soga' => 'decimal:2',
        'projected_xga' => 'decimal:4',
        'projected_xsoga' => 'decimal:4',
        'projected_profile_share_against' => 'decimal:6',
        'goal_probability_against' => 'decimal:6',
        'shot_on_goal_probability_against' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Owning skater defensive projection.
     *
     * @return BelongsTo<NhlSkaterDefensiveChanceProjection, NhlSkaterDefensiveChanceProjectionBucket>
     */
    public function projection(): BelongsTo
    {
        return $this->belongsTo(NhlSkaterDefensiveChanceProjection::class, 'skater_defensive_chance_projection_id');
    }
}
