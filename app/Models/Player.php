<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\PlayerRanking;
use App\Models\RankingProfile;
use App\Models\Stat;
use App\Models\Contract;
use App\Models\NhlUnit;

class Player extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $guarded = [];

    /**
     * The user's “current” ranking for this player (latest in their default profile).
     *
     * @return HasOne<PlayerRanking>
     */
    public function currentRanking(): HasOne
    {
        $profile = auth()->user()->rankingProfiles()->first();

        return $this->hasOne(PlayerRanking::class)
                    ->where('ranking_profile_id', $profile?->id)
                    ->latestOfMany();
    }

    /**
     * All of this user's individual ranking entries for this player.
     *
     * @return HasMany<PlayerRanking>
     */
    public function rankingsForUser(): HasMany
    {
        $profile = auth()->user()->rankingProfiles()->first();

        return $this->hasMany(PlayerRanking::class)
                    ->where('ranking_profile_id', $profile?->id)
                    ->orderByDesc('created_at');
    }

    /**
     * All ranking profiles that include this player.
     *
     * @return BelongsToMany<RankingProfile>
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
     *
     * @return HasMany<Stat>
     */
    public function stats(): HasMany
    {
        return $this->hasMany(Stat::class);
    }

    /**
     * Get the most recent Stat record where league_abbrev = 'NHL'.
     *
     * @return HasOne<Stat>
     */
    public function latestNhlStat(): HasOne
    {
        return $this->hasOne(Stat::class)
                    ->where('league_abbrev', 'NHL')
                    ->latestOfMany('season_id');
    }

    /**
     * Get the contracts associated with the player.
     *
     * @return HasMany<Contract>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }


    /**
     * Get the FantraxPlayer record linked to this player.
     */
    public function fantraxPlayer(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\FantraxPlayer::class, 'player_id', 'id');
    }




    public function getAgeAttribute(): ?int
    {
        return $this->dob
            ? \Carbon\Carbon::parse($this->dob)->age
            : null;
    }

    public function setMetaAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['meta'] = null;
            return;
        }

        if (is_string($value)) {
            $this->attributes['meta'] = $value;
            return;
        }

        $this->attributes['meta'] = json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * All NHL units this player belongs to.
     *
     * @return BelongsToMany<NhlUnit>
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(
            NhlUnit::class,
            'nhl_unit_players',
            'player_id',
            'unit_id'
        );
    }
}
