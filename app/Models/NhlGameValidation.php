<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persisted validation state for a computed NHL game artifact.
 */
class NhlGameValidation extends Model
{
    public const TYPE_SUMMARY_BOXSCORE = 'summary_boxscore';
    public const TYPE_PBP_HTML_REPORT = 'pbp_html_report';

    public const STATUS_APPROVED = 'approved';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ACCEPTED_EXCEPTION = 'accepted_exception';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_INVALIDATED = 'invalidated';
    public const STATUS_SHIFTCHART_MISMATCH = 'shiftchart-mismatch';

    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }

    public function deltas(): HasMany
    {
        return $this->hasMany(NhlGameValidationDelta::class, 'validation_id');
    }

    public function pbpSourceMismatches(): HasMany
    {
        return $this->hasMany(NhlPbpSourceMismatch::class, 'validation_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
