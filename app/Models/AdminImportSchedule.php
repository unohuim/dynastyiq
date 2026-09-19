<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminImportSchedule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_seconds' => 'integer',
        'last_dispatched_at' => 'datetime',
        'next_due_at' => 'datetime',
    ];
}
