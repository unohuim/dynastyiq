<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class NhlGame
 *
 * Represents a single NHL game with detailed metadata and relationships.
 *
 * @package App\Models
 */
class NhlGame extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'nhl_game_id';


    protected $casts = [
        'limited_scoring'        => 'boolean',
        'shootout_in_use'        => 'boolean',
        'ot_in_use'              => 'boolean',
        'clock_running'          => 'boolean',
        'clock_in_intermission'  => 'boolean',
        'tv_broadcasts'          => 'array',
        'game_outcome'           => 'array',
        'game_date'              => 'date',
        'start_time_utc'         => 'datetime',
    ];

    /**
     * Get the home team of the game.
     */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * Get the away team of the game.
     */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /**
     * Get all shifts for this game.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(NhlShift::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Get all play-by-play events for this game.
     */
    public function playByPlays(): HasMany
    {
        return $this->hasMany(PlayByPlay::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Get all units associated with this game (optional).
     */
    public function units(): HasMany
    {
        return $this->hasMany(NhlUnit::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Get all unit game summaries for this game.
     */
    public function unitGameSummaries(): HasMany
    {
        return $this->hasMany(NhlUnitGameSummary::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Get all player game summaries for this game.
     */
    public function playerGameSummaries(): HasMany
    {
        return $this->hasMany(NhlGameSummary::class, 'nhl_game_id', 'nhl_game_id');
    }


    public function getTeamIdByAbbrev(string $abbr): ?int
    {
        if (strcasecmp($abbr, $this->home_team_abbrev) === 0) {
            return $this->home_team_id;
        }
        if (strcasecmp($abbr, $this->away_team_abbrev) === 0) {
            return $this->away_team_id;
        }
        return null;
    }
}
