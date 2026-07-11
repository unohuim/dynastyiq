<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-earned fantasy player stats scoped to a platform league.
 */
class PlatformLeaguePlayerStat extends Model
{
    protected $fillable = [
        'platform_league_id',
        'platform_team_id',
        'player_id',
        'platform',
        'provider_identity_key',
        'platform_player_id',
        'season',
        'scoring_period',
        'scope',
        'stats',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'stats' => 'array',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Parent platform league.
     */
    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }

    /**
     * Fantasy team credited by the provider, when present.
     */
    public function platformTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class, 'platform_team_id');
    }

    /**
     * Matched canonical player, when known.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
