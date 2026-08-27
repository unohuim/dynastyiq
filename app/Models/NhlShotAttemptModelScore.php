<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned model score for one NHL shot-attempt fact.
 */
class NhlShotAttemptModelScore extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'game_date' => 'date',
        'is_scored' => 'boolean',
        'probability' => 'decimal:6',
        'is_high_danger' => 'boolean',
        'high_danger_threshold' => 'decimal:6',
        'matched_bucket_payload' => 'array',
        'scored_at' => 'datetime',
    ];

    /**
     * Owning expected-goals model.
     *
     * @return BelongsTo<NhlExpectedGoalsModel, NhlShotAttemptModelScore>
     */
    public function expectedGoalsModel(): BelongsTo
    {
        return $this->belongsTo(NhlExpectedGoalsModel::class, 'expected_goals_model_id');
    }
}
