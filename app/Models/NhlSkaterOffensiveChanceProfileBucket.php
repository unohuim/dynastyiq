<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historical skater offensive chance profile for one granular bucket.
 */
class NhlSkaterOffensiveChanceProfileBucket extends Model
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
        'source_toi_seconds' => 'integer',
        'source_sat_for' => 'integer',
        'source_sog_for' => 'integer',
        'source_goals_for' => 'integer',
        'source_xgf' => 'decimal:4',
        'source_xsog' => 'decimal:4',
        'source_xgf_per_60' => 'decimal:4',
        'source_xsog_per_60' => 'decimal:4',
        'source_profile_share' => 'decimal:6',
        'goal_probability' => 'decimal:6',
        'shot_on_goal_probability' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'profile_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'profiled_at' => 'datetime',
    ];
}
