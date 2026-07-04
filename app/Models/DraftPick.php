<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DraftPick extends Model
{
    protected $fillable = [
        'draft_id',
        'provider_pick_key',
        'overall_pick',
        'round',
        'pick',
        'pick_in_round',
        'platform_team_id',
        'provider_team_id',
        'player_id',
        'provider_player_id',
        'picked_by_user_id',
        'source',
        'status',
        'picked_at',
        'detected_at',
        'announced_at',
        'expires_at',
        'payload_hash',
        'raw_payload',
    ];

    protected $casts = [
        'overall_pick' => 'integer',
        'round' => 'integer',
        'pick' => 'integer',
        'pick_in_round' => 'integer',
        'picked_at' => 'datetime',
        'detected_at' => 'datetime',
        'announced_at' => 'datetime',
        'expires_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function platformTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function pickedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by_user_id');
    }
}
