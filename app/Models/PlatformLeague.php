<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class PlatformLeague extends Model
{
    protected $table = 'platform_leagues';

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


    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(
            League::class,
            'league_platform_league',
            'platform_league_id',
            'league_id'
        )->withPivot(['linked_at', 'status', 'meta', 'created_at', 'updated_at'])
         ->withTimestamps();
    }


    public function league(): HasOneThrough
    {
        return $this->hasOneThrough(
            League::class,                // related
            LeaguePlatformLeague::class,  // pivot model
            'platform_league_id',         // FK on pivot -> platform_leagues.id
            'id',                         // PK on leagues
            'id',                         // PK on platform_leagues
            'league_id'                   // FK on pivot -> leagues.id
        );
    }


    public function teams(): HasMany
    {
        return $this->hasMany(PlatformTeam::class, 'platform_league_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'league_user_teams',
            'platform_league_id',
            'user_id'
        )->withPivot(['league_team_id', 'is_active', 'extras', 'synced_at'])
         ->withTimestamps();
    }

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
