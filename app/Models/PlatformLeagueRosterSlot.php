<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-neutral roster slot settings for a platform league.
 */
class PlatformLeagueRosterSlot extends Model
{
    protected $fillable = [
        'platform_league_id',
        'slot',
        'slot_type',
        'position_type',
        'count',
        'sort_order',
        'raw_payload',
    ];

    protected $casts = [
        'count' => 'integer',
        'sort_order' => 'integer',
        'raw_payload' => 'array',
    ];

    /**
     * Parent platform league.
     */
    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }
}
