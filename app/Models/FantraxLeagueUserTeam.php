<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantraxLeagueUserTeam extends Model
{
    protected $fillable = [
        'user_id',
        'fantrax_league_id', // external
        'fantrax_team_id',   // external
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Match by external league id (not PK)
    public function league(): BelongsTo
    {
        return $this->belongsTo(FantraxLeague::class, 'fantrax_league_id', 'fantrax_league_id');
    }

    // Match by external team id + same league constraint
    public function team(): BelongsTo
    {
        return $this->belongsTo(FantraxTeam::class, 'fantrax_team_id', 'fantrax_team_id')
            ->whereColumn('fantrax_league_user_teams.fantrax_league_id', 'fantrax_teams.fantrax_league_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
