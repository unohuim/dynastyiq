<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Persisted timing and dispatch evidence for allowlisted scheduled processes. */
class ScheduledProcess extends Model
{
    protected $guarded = [];

    /** Cast timing evidence and structured process-specific settings. */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'frequency_hours' => 'integer', 'settings' => 'array',
            'last_dispatched_at' => 'immutable_datetime', 'next_due_at' => 'immutable_datetime'];
    }
}
