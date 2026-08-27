<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned NHL expected-goals model metadata.
 */
class NhlExpectedGoalsModel extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'training_filters' => 'array',
        'feature_config' => 'array',
        'calibration_config' => 'array',
        'metrics' => 'array',
        'trained_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * Learned probability buckets for this model.
     *
     * @return HasMany<NhlExpectedGoalsModelBucket>
     */
    public function buckets(): HasMany
    {
        return $this->hasMany(NhlExpectedGoalsModelBucket::class, 'expected_goals_model_id');
    }

    /**
     * Shot-attempt predictions scored by this model.
     *
     * @return HasMany<NhlShotAttemptPrediction>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(NhlShotAttemptPrediction::class, 'expected_goals_model_id');
    }

    /**
     * Shot-attempt facts scored by this model.
     *
     * @return HasMany<NhlShotAttemptModelScore>
     */
    public function shotAttemptScores(): HasMany
    {
        return $this->hasMany(NhlShotAttemptModelScore::class, 'expected_goals_model_id');
    }
}
