<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FantraxDraftPick extends Model
{
    protected $fillable = [
        'platform_league_id',
        'provider_pick_key',
        'overall_pick',
        'round',
        'pick',
        'pick_in_round',
        'fantrax_team_id',
        'fantrax_player_id',
        'drafted_at',
        'detected_at',
        'announced_at',
        'payload_hash',
        'raw_payload',
    ];

    protected $casts = [
        'overall_pick' => 'integer',
        'round' => 'integer',
        'pick' => 'integer',
        'pick_in_round' => 'integer',
        'drafted_at' => 'datetime',
        'detected_at' => 'datetime',
        'announced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }
}
