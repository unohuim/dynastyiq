<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Readable aggregate SAT profile bucket for one NHL staff team context.
 */
class NhlStaffSatAggregateProfileBucket extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'aggregate_dimensions' => 'array',
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
        'confidence_score' => 'decimal:4',
        'shrinkage_weight' => 'decimal:4',
        'included_bucket_count' => 'integer',
        'included_bucket_keys' => 'array',
        'profile_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'profiled_at' => 'datetime',
    ];

    /**
     * Staff identity for this aggregate SAT profile bucket.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(NhlStaff::class, 'nhl_staff_id');
    }
}
