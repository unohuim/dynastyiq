<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\NhlValidationTroubleshootingExporter;
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

    protected static function booted(): void
    {
        static::saved(function (NhlGameValidation $validation): void {
            if (! $validation->shouldDeleteTroubleshootingDirectory()) {
                return;
            }

            app(NhlValidationTroubleshootingExporter::class)
                ->deleteGameDirectory((int) $validation->nhl_game_id);
        });
    }

    /**
     * Determine whether validation evidence should remain on disk for triage.
     */
    public function shouldRetainTroubleshootingDirectory(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_INCOMPLETE,
            self::STATUS_INVALIDATED,
        ], true);
    }

    /**
     * Determine whether validation evidence should be removed from disk.
     */
    public function shouldDeleteTroubleshootingDirectory(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_ACCEPTED_EXCEPTION,
            self::STATUS_SHIFTCHART_MISMATCH,
        ], true);
    }

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
