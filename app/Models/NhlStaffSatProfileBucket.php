<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historical SAT chance profile bucket for one NHL staff team context.
 */
class NhlStaffSatProfileBucket extends Model
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
        'source_games' => 'decimal:2',
        'source_sat' => 'integer',
        'source_unblocked_sat' => 'integer',
        'source_sog' => 'integer',
        'source_goals' => 'integer',
        'source_xg' => 'decimal:4',
        'source_xsog' => 'decimal:4',
        'source_profile_share' => 'decimal:6',
        'goal_probability' => 'decimal:6',
        'shot_on_goal_probability' => 'decimal:6',
        'prior_fallback_level' => 'integer',
        'prior_sat' => 'integer',
        'prior_weight_sat' => 'integer',
        'shrinkage_weight' => 'decimal:4',
        'confidence_score' => 'decimal:4',
        'profile_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'profiled_at' => 'datetime',
    ];

    /**
     * Staff identity for this SAT profile bucket.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(NhlStaff::class, 'nhl_staff_id');
    }
}
