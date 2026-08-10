<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Name-normalized NHL staff identity observed from provider game context.
 */
class NhlStaff extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Game/team assignments for this staff member.
     */
    public function gameAssignments(): HasMany
    {
        return $this->hasMany(NhlGameTeamStaff::class, 'nhl_staff_id');
    }
}
