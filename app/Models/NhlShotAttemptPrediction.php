<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned expected-goals prediction for one NHL shot-attempt fact.
 */
class NhlShotAttemptPrediction extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_scored' => 'boolean',
        'raw_xg' => 'decimal:6',
        'calibrated_xg' => 'decimal:6',
        'xg' => 'decimal:6',
        'matched_bucket_payload' => 'array',
        'game_date' => 'date',
    ];

    /**
     * Owning expected-goals model.
     *
     * @return BelongsTo<NhlExpectedGoalsModel, NhlShotAttemptPrediction>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(NhlExpectedGoalsModel::class, 'expected_goals_model_id');
    }
}

