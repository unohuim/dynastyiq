<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One evaluation/game/strength/quarter experiment with resumable bounded work. */
class NhlEvaluationGameForecast extends Model
{
    public const ACTIVE = ['queued', 'running', 'comparing'];

    protected $guarded = ['id'];

    protected $casts = ['lineup' => 'array', 'source_cutoff_at' => 'datetime',
        'forecasted_at' => 'datetime', 'compared_at' => 'datetime', 'cursor' => 'integer', 'total' => 'integer'];

    /** Only a fully refreshed player chain may be skipped by simulation. */
    public function hasCurrentEvidence(): bool
    {
        return ($this->lineup[0]['evidence_version'] ?? null) === \App\Services\NhlEvaluationGameForecaster::CONTEXT_VERSION;
    }

    /** Failed or abandoned work can resume without rewriting committed forecasts. */
    public function canResume(): bool
    {
        return $this->status === 'failed' || (in_array($this->status, self::ACTIVE, true)
            && $this->updated_at->lt(now()->subMinutes(3)));
    }
}
