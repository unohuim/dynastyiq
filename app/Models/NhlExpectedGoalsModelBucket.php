<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Learned xG bucket for a versioned NHL expected-goals model.
 */
class NhlExpectedGoalsModelBucket extends Model
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
        'raw_goal_rate' => 'decimal:6',
        'smoothed_goal_probability' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'shrinkage_weight' => 'decimal:4',
    ];

    /**
     * Owning expected-goals model.
     *
     * @return BelongsTo<NhlExpectedGoalsModel, NhlExpectedGoalsModelBucket>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(NhlExpectedGoalsModel::class, 'expected_goals_model_id');
    }
}
