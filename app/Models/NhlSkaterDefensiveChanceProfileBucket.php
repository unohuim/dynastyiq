<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historical skater on-ice defensive chance profile for one resolved bucket.
 */
class NhlSkaterDefensiveChanceProfileBucket extends Model
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
        'source_sat_against_on_ice' => 'integer',
        'source_sog_against_on_ice' => 'integer',
        'source_goals_against_on_ice' => 'integer',
        'source_xga_on_ice' => 'decimal:4',
        'source_xsoga_on_ice' => 'decimal:4',
        'source_xga_per_60' => 'decimal:4',
        'source_xsoga_per_60' => 'decimal:4',
        'source_profile_share_against' => 'decimal:6',
        'goal_probability_against' => 'decimal:6',
        'shot_on_goal_probability_against' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'profile_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'profiled_at' => 'datetime',
    ];
}
