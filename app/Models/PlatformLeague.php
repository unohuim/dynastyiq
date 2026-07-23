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
        'logo_url',
        'settings',
        'scoring_settings',
        'synced_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'scoring_settings' => 'array',
        'synced_at' => 'datetime',
    ];


    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(
            League::class,
            'league_platform_league',
            'platform_league_id',
            'league_id'
        )->withPivot(['linked_at', 'archived_at', 'status', 'meta', 'created_at', 'updated_at'])
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

    /**
     * Return the active internal league wrapper for this provider scope.
     */
    public function activeLeagueForScope(?string $scopeType = null, ?string $scopeKey = null): ?League
    {
        return LeaguePlatformLeague::query()
            ->with('league')
            ->where('platform_league_id', $this->id)
            ->active()
            ->forProviderScope($scopeType, $scopeKey)
            ->latest('linked_at')
            ->latest('id')
            ->first()
            ?->league;
    }


    public function teams(): HasMany
    {
        return $this->hasMany(PlatformTeam::class, 'platform_league_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class, 'platform_league_id');
    }

    /**
     * Configured roster slots for this platform league.
     */
    public function rosterSlots(): HasMany
    {
        return $this->hasMany(PlatformLeagueRosterSlot::class, 'platform_league_id')
            ->orderBy('sort_order');
    }

    /**
     * First-class provider scoring categories for this platform league.
     */
    public function scoringCategories(): HasMany
    {
        return $this->hasMany(PlatformLeagueScoringCategory::class, 'platform_league_id')
            ->orderBy('sort_order');
    }

    /**
     * Provider-earned fantasy player stats for this platform league.
     */
    public function playerStats(): HasMany
    {
        return $this->hasMany(PlatformLeaguePlayerStat::class, 'platform_league_id');
    }

    /**
     * Imported fantasy platform transactions for this league.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PlatformTransaction::class, 'platform_league_id');
    }

    /**
     * User-owned projected cap assumptions for cap planning.
     */
    public function capContractProjections(): HasMany
    {
        return $this->hasMany(CapContractProjection::class, 'platform_league_id');
    }

    /**
     * User-local settings used while a league has no connected league admin.
     */
    public function userSettings(): HasMany
    {
        return $this->hasMany(PlatformLeagueUserSetting::class, 'platform_league_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'league_user_teams',
            'platform_league_id',
            'user_id'
        )->withPivot(['team_id', 'is_active', 'is_visible', 'sort_order', 'extras', 'synced_at'])
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
