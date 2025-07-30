<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\PlayerRanking;
use App\Models\RankingProfile;
use App\Models\Stat;
use Carbon\Carbon;

class Player extends Model
{
    protected $guarded = [];

    /**
     * The user's “current” ranking for this player (latest in their default profile).
     */
    public function currentRanking()
    {
        return $this->hasOne(PlayerRanking::class)
                    ->where('author_id', auth()->id())
                    ->latestOfMany();
    }

    /**
     * All of this user's individual ranking entries for this player.
     */
    public function rankingsForUser(): HasMany
    {
        return $this->hasMany(PlayerRanking::class)
                    ->where('author_id', auth()->id())
                    ->orderByDesc('created_at');
    }

    /**
     * All ranking profiles that include this player.
     */
    public function rankingProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            RankingProfile::class,
            'player_rankings',
            'player_id',
            'ranking_profile_id'
        )
        ->withPivot(['score', 'description', 'visibility', 'settings'])
        ->withTimestamps();
    }

    /**
     * A Player has many Stats.
     */
    public function stats(): HasMany
    {
        return $this->hasMany(Stat::class);
    }

    /**
     * Get the most recent Stat record where league_abbrev = 'NHL'.
     */
    public function latestNhlStat(): HasMany
    {
        return $this->hasOne(Stat::class)
                    ->where('league_abbrev', 'NHL')
                    ->latestOfMany('season_id');
    }

    /**
     * Get the contracts associated with the player.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Compute the player's age in whole years.
     */
    public function age(): int
    {
        return Carbon::parse($this->dob)->diffInYears(now());
    }
}
