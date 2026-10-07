<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One durable game-sized work item for a pregame-context build. */
class NhlPregameContextRunGame extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_READY = 'ready';
    public const STATE_PROCESSING = 'processing';
    public const STATE_COMPLETE = 'complete';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'game_date' => 'date',
        'readiness' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(NhlPregameContextRun::class, 'run_id');
    }
}
