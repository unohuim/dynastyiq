<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhlStartingGoalieObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'game_date' => 'date',
        'is_home' => 'boolean',
        'provider_published_at' => 'datetime',
        'fetched_at' => 'datetime',
        'raw_evidence' => 'array',
    ];
}
