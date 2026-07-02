<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-visible NHL game import orchestration request.
 */
class NhlGameImportRun extends Model
{
    public const ACTION_DISCOVER = 'discover';
    public const ACTION_PROCESS = 'process';
    public const ACTION_SEASON_SYNC = 'season-sync';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const MODE_DATE = 'date';
    public const MODE_SEASON = 'season';
    public const MODE_NEWDAYS = 'newdays';
    public const MODE_RANGE = 'range';
    public const MODE_DAYS = 'days';
    public const MODE_DEFAULT = 'default';

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'payload' => 'array',
    ];

    /**
     * Get the admin user who queued the run.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
