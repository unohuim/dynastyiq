<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Persisted configuration for one scheduled admin-import lane. */
class AdminImportSchedule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'game_sync_timing' => 'array',
        'enabled' => 'boolean',
        'lane_enabled' => 'boolean',
        'interval_seconds' => 'integer',
        'last_dispatched_at' => 'datetime',
        'next_due_at' => 'datetime',
    ];
}
