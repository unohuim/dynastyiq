<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhlPlayerInjuryObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw_evidence' => 'array',
        'anticipated_return_date' => 'date',
        'provider_published_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];
}
