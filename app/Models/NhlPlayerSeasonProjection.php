<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned player-season projection rollup for NHL skater outputs.
 */
class NhlPlayerSeasonProjection extends Model
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
        'source_goals' => 'integer',
        'source_model_goals' => 'integer',
        'source_xgf' => 'decimal:4',
        'source_goals_above_xgf' => 'decimal:4',
        'source_xsog' => 'decimal:4',
        'projected_games' => 'decimal:2',
        'projected_xsat' => 'decimal:2',
        'projected_xsog' => 'decimal:2',
        'projected_xgf' => 'decimal:4',
        'finishing_regression_weight' => 'decimal:4',
        'projected_goals_adjustment' => 'decimal:4',
        'projected_goals' => 'decimal:4',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'projected_at' => 'datetime',
    ];

    /**
     * Profile bucket rows that explain this season projection.
     *
     * @return HasMany<NhlPlayerProjectionProfileBucket>
     */
    public function profileBuckets(): HasMany
    {
        return $this->hasMany(NhlPlayerProjectionProfileBucket::class, 'player_season_projection_id');
    }
}
