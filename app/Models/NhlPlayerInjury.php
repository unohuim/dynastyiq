<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhlPlayerInjury extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sources' => 'array',
        'anticipated_return_date' => 'date',
        'first_observed_at' => 'datetime',
        'last_observed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
