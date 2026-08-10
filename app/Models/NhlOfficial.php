<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Name-normalized NHL official identity observed from provider game context.
 */
class NhlOfficial extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Game assignments for this official.
     */
    public function gameAssignments(): HasMany
    {
        return $this->hasMany(NhlGameOfficial::class, 'nhl_official_id');
    }
}
