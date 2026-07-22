<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Event-level difference between imported API PBP and official HTML PBP reports.
 */
class NhlPbpSourceMismatch extends Model
{
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_INFO = 'info';

    protected $guarded = [];

    protected $casts = [
        'api_event' => 'array',
        'html_event' => 'array',
    ];

    public function validation(): BelongsTo
    {
        return $this->belongsTo(NhlGameValidation::class, 'validation_id');
    }

    public function playByPlay(): BelongsTo
    {
        return $this->belongsTo(PlayByPlay::class, 'play_by_play_id');
    }
}
