<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


class PlatformTeam extends Model
{
    protected $fillable = [
        'platform_league_id',
        'platform_team_id',
        'name',
        'short_name',
        'logo_url',
        'extras',
        'synced_at',
    ];

    protected $casts = [
        'extras' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Parent league.
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }



    public function roster(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'platform_roster_memberships', 'platform_team_id', 'player_id')
            ->withPivot(['platform', 'platform_player_id', 'slot', 'status', 'eligibility', 'metadata', 'starts_at', 'ends_at'])
            ->wherePivotNull('ends_at');
    }


    /**
     * Users assigned to this team.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'league_user_teams', 'team_id', 'user_id')
            ->withPivot(['is_active', 'is_visible', 'extras', 'synced_at'])
            ->withTimestamps();
    }

    /**
     * User-owned projected cap assumptions for this fantasy team.
     */
    public function capContractProjections(): HasMany
    {
        return $this->hasMany(CapContractProjection::class, 'platform_team_id');
    }

    /**
     * Transaction entries where this team is the provider-perspective team.
     */
    public function transactionEntries(): HasMany
    {
        return $this->hasMany(PlatformTransactionEntry::class, 'platform_team_id');
    }

    /**
     * Transaction entries where an asset moved from this team.
     */
    public function outgoingTransactionEntries(): HasMany
    {
        return $this->hasMany(PlatformTransactionEntry::class, 'from_platform_team_id');
    }

    /**
     * Transaction entries where an asset moved to this team.
     */
    public function incomingTransactionEntries(): HasMany
    {
        return $this->hasMany(PlatformTransactionEntry::class, 'to_platform_team_id');
    }

    /**
     * Limit to a specific league.
     */
    public function scopeInLeague(Builder $query, PlatformLeague|int $league): Builder
    {
        $leagueId = $league instanceof PlatformLeague ? $league->id : $league;

        return $query->where('platform_league_id', $leagueId);
    }

    /**
     * Filter by provider via parent league.
     */
    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->whereHas('league', static function (Builder $q) use ($platform): void {
            $q->where('platform', $platform);
        });
    }

    public function scopeFantrax(Builder $query): Builder
    {
        return $this->scopePlatform($query, 'fantrax');
    }

    public function scopeYahoo(Builder $query): Builder
    {
        return $this->scopePlatform($query, 'yahoo');
    }

    public function scopeEspn(Builder $query): Builder
    {
        return $this->scopePlatform($query, 'espn');
    }

    /**
     * Locate by provider triple: (platform, platform_league_id, platform_team_id).
     */
    public function scopeProviderTriple(
        Builder $query,
        string $platform,
        string $platformLeagueId,
        string $platformTeamId
    ): Builder {
        return $query->where('platform_team_id', $platformTeamId)
            ->whereHas('league', static function (Builder $q) use ($platform, $platformLeagueId): void {
                $q->where('platform', $platform)
                    ->where('platform_league_id', $platformLeagueId);
            });
    }

    public function getIdentifierAttribute(): string
    {
        $leagueExt = $this->league?->platform_league_id ?? 'unknown';

        return $leagueExt . '/' . $this->platform_team_id;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->short_name ?: $this->name;
    }
}
