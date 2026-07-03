<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FantraxDraftState extends Model
{
    protected $fillable = [
        'platform_league_id',
        'draft_at',
        'status',
        'current_draft_pick_count',
        'poll_interval_minutes',
        'draft_results_hash',
        'draft_picks_hash',
        'raw_draft_results',
        'raw_draft_pick_info',
        'last_checked_at',
        'last_detected_pick_at',
        'meta',
    ];

    protected $casts = [
        'draft_at' => 'datetime',
        'current_draft_pick_count' => 'integer',
        'poll_interval_minutes' => 'integer',
        'raw_draft_results' => 'array',
        'raw_draft_pick_info' => 'array',
        'last_checked_at' => 'datetime',
        'last_detected_pick_at' => 'datetime',
        'meta' => 'array',
    ];

    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }
}
