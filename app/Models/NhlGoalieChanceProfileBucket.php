<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historical goalie chance mix and performance-over-expected row for one resolved bucket.
 */
class NhlGoalieChanceProfileBucket extends Model
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
        'source_sat_against' => 'integer',
        'source_sog_against' => 'integer',
        'source_goals_against' => 'integer',
        'source_xga' => 'decimal:4',
        'source_xsoga' => 'decimal:4',
        'source_gsax' => 'decimal:4',
        'source_gsax_per_100_sat_against' => 'decimal:4',
        'source_profile_share' => 'decimal:6',
        'goal_probability_against' => 'decimal:6',
        'shot_on_goal_probability_against' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'profile_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'profiled_at' => 'datetime',
    ];
}
