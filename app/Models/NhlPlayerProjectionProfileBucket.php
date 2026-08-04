<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned player projection contribution for one resolved shot profile bucket.
 */
class NhlPlayerProjectionProfileBucket extends Model
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
        'source_goals' => 'integer',
        'source_model_goals' => 'integer',
        'source_xgf' => 'decimal:4',
        'source_xsog' => 'decimal:4',
        'source_profile_share' => 'decimal:6',
        'projected_xsat' => 'decimal:2',
        'projected_xsog' => 'decimal:2',
        'projected_xgf' => 'decimal:4',
        'projected_goals' => 'decimal:4',
        'goal_probability' => 'decimal:6',
        'shot_on_goal_probability' => 'decimal:6',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Owning player-season projection.
     *
     * @return BelongsTo<NhlPlayerSeasonProjection, NhlPlayerProjectionProfileBucket>
     */
    public function seasonProjection(): BelongsTo
    {
        return $this->belongsTo(NhlPlayerSeasonProjection::class, 'player_season_projection_id');
    }
}
