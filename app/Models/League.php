<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class League extends Model
{
    protected $fillable = [
        'platform',
        'platform_league_id',
        'name',
        'sport',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    /**
     * Teams registered in this league.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(LeagueTeam::class);
    }

    /**
     * User↔Team assignments within this league.
     */
    public function userTeams(): HasMany
    {
        return $this->hasMany(LeagueUserTeam::class);
    }

    /**
     * Users participating in this league.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'league_user_teams')
            ->withPivot(['league_team_id', 'is_active', 'extras', 'synced_at'])
            ->withTimestamps();
    }

    /**
     * Scope by platform.
     */
    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeFantrax(Builder $query): Builder
    {
        return $query->where('platform', 'fantrax');
    }

    public function scopeYahoo(Builder $query): Builder
    {
        return $query->where('platform', 'yahoo');
    }

    public function scopeEspn(Builder $query): Builder
    {
        return $query->where('platform', 'espn');
    }

    public function scopeProviderPair(Builder $query, string $platform, string $platformLeagueId): Builder
    {
        return $query->where('platform', $platform)
            ->where('platform_league_id', $platformLeagueId);
    }

    public function getIdentifierAttribute(): string
    {
        return "{$this->platform}:{$this->platform_league_id}";
    }
}
